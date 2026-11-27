<?php

declare(strict_types=1);

namespace App\Repo\Infrastructure\Git;

use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\Signature\SignatureStatus;
use App\Repo\Domain\Signature\TrustSource;
use Symfony\Component\Process\Process;

/**
 * Verifies commit signatures against the account trust source using git's own
 * machinery (`git show --format=%G?`). SSH signatures are checked against an
 * allowed-signers file built from trusted SSH keys; OpenPGP signatures against an
 * isolated keyring built from trusted GPG keys. A commit is {@see SignatureStatus::Verified}
 * only when signed by a trusted key.
 */
final class CommitSignatureVerifier
{
    private ?string $allowedSignersFile = null;
    private ?string $gpgHome = null;
    private bool $prepared = false;

    public function __construct(private readonly TrustSource $trustSource)
    {
    }

    public function __destruct()
    {
        if (null !== $this->allowedSignersFile && is_file($this->allowedSignersFile)) {
            @unlink($this->allowedSignersFile);
        }
        if (null !== $this->gpgHome && is_dir($this->gpgHome)) {
            $this->removeDir($this->gpgHome);
        }
    }

    public function verify(GitRepository $repo, string $sha): SignatureStatus
    {
        if (1 !== preg_match('/^[0-9a-f]{40}$/', $sha)) {
            return SignatureStatus::Unverified;
        }

        $this->prepare();

        $args = ['-c', 'gpg.ssh.allowedSignersFile='.($this->allowedSignersFile ?? '/dev/null')];
        $args[] = 'show';
        $args[] = '-s';
        $args[] = '--format=%G?';
        $args[] = new Revision($sha);

        $env = null !== $this->gpgHome ? ['GNUPGHOME' => $this->gpgHome] : null;

        $process = GitCommand::run($repo->path, $args, null, $env);
        if (!$process->isSuccessful()) {
            return SignatureStatus::Unverified;
        }

        return SignatureStatus::fromGitStatus($process->getOutput());
    }

    private function prepare(): void
    {
        if ($this->prepared) {
            return;
        }
        $this->prepared = true;

        $lines = [];
        foreach ($this->trustSource->sshTrustedKeys() as $authorizedKey) {
            $fields = preg_split('/\s+/', trim($authorizedKey)) ?: [];
            if (\count($fields) < 2) {
                continue;
            }
            // `* <algo> <base64>`: trust the key for any signer identity.
            $lines[] = '* '.$fields[0].' '.$fields[1];
        }

        if ([] !== $lines) {
            $file = tempnam(sys_get_temp_dir(), 'gickers_signers_');
            if (false !== $file) {
                file_put_contents($file, implode("\n", $lines)."\n");
                $this->allowedSignersFile = $file;
            }
        }

        $gpgKeys = $this->trustSource->gpgTrustedKeys();
        if ([] !== $gpgKeys) {
            $home = sys_get_temp_dir().'/gickers_gpg_'.bin2hex(random_bytes(6));
            if (mkdir($home, 0700, true)) {
                $this->gpgHome = $home;
                foreach ($gpgKeys as $armored) {
                    $import = new Process(['gpg', '--homedir', $home, '--import'], null, ['GNUPGHOME' => $home]);
                    $import->setInput($armored);
                    $import->run();
                }
            }
        }
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
