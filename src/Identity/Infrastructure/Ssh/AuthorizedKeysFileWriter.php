<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Ssh;

use App\Identity\Domain\Event\PublicKeysChanged;
use App\Identity\Domain\Model\PublicKey;
use App\Identity\Domain\UserStore;
use App\Shared\Infrastructure\Config\ServerSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class AuthorizedKeysFileWriter
{
    public function __construct(
        private UserStore $userStore,
        private ServerSettings $serverSettings,
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    #[AsEventListener]
    public function __invoke(PublicKeysChanged $event): void
    {
        $this->write();
    }

    public function write(): void
    {
        $sshDir = $this->serverSettings->sshPath();
        if (!is_dir($sshDir)) {
            mkdir($sshDir, 0700, true);
        }

        $lines = [];
        foreach ($this->userStore->all() as $user) {
            foreach ($user->publicKeys() as $key) {
                $lines[] = $this->formatLine($key);
            }
        }

        $content = [] === $lines ? '' : implode("\n", $lines)."\n";
        $targetFile = $sshDir.'/authorized_keys';
        $tmpFile = $sshDir.'/authorized_keys.tmp.'.bin2hex(random_bytes(6));

        if (false === @file_put_contents($tmpFile, $content)) {
            throw new \RuntimeException(sprintf('Failed to write temporary file "%s"', $tmpFile));
        }
        chmod($tmpFile, 0600);

        if (!@rename($tmpFile, $targetFile)) {
            @unlink($tmpFile);
            throw new \RuntimeException(sprintf('Failed to atomically rename "%s" to "%s"', $tmpFile, $targetFile));
        }

        chmod($targetFile, 0600);
    }

    public function formatLine(PublicKey $key): string
    {
        $command = sprintf(
            'APP_ENV=%s %s %s soft:ssh:dispatch --key-fingerprint=%s',
            escapeshellarg($this->environment),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($this->projectDir.'/bin/console'),
            escapeshellarg($key->fingerprint())
        );

        $escapedCommand = str_replace(['\\', '"'], ['\\\\', '\\"'], $command);

        return sprintf(
            'command="%s",no-port-forwarding,no-X11-forwarding,no-agent-forwarding %s',
            $escapedCommand,
            $key->authorizedKey()
        );
    }
}
