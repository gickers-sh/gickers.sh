<?php

declare(strict_types=1);

namespace App\UI\Http\Git;

use App\Identity\Domain\Model\User;
use App\Repo\Infrastructure\Git\GitCommand;
use App\Repo\Infrastructure\Git\Revision;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\Shared\Infrastructure\Git\PktLine;
use App\Shared\Infrastructure\Process\EnvCommand;

/**
 * Handles Git Smart HTTP server processes (stateless-rpc for upload-pack and receive-pack)
 * and Dumb HTTP support commands, matching Go `pkg/web/git.go`.
 */
final readonly class GitServiceProcess
{
    public function __construct(
        private ServerSettings $settings,
    ) {
    }

    /**
     * Advertises references for Smart HTTP (git upload-pack / receive-pack --stateless-rpc --advertise-refs).
     *
     * @return string Advertisement body with pkt-line service header (v0/v1) and git refs output
     */
    public function advertise(
        string $repoPath,
        string $service,
        ?string $protocol = null,
        ?User $user = null,
        ?string $repoName = null,
    ): string {
        $this->assertValidService($service);

        $subcommand = str_replace('git-', '', $service);
        $command = ['git', $subcommand, '--stateless-rpc', '--advertise-refs', $repoPath];

        $env = $this->buildEnv($repoPath, $repoName, $user, $protocol);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(EnvCommand::wrap($command, $env), $descriptors, $pipes, $repoPath);
        if (!\is_resource($process)) {
            throw new \RuntimeException(sprintf('Failed to execute git %s for info/refs', $subcommand));
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if (0 !== $exitCode) {
            throw new \RuntimeException(sprintf('git %s --advertise-refs exited with code %d: %s', $subcommand, $exitCode, $stderr));
        }

        $version = $this->parseProtocolVersion($protocol);
        $header = '';
        if ($version < 2) {
            $header = PktLine::encode(sprintf("# service=%s\n", $service)).PktLine::flush();
        }

        return $header.(false !== $stdout ? $stdout : '');
    }

    /**
     * Streams request body to git <service> --stateless-rpc and streams stdout directly to output.
     *
     * @param resource $inputStream
     */
    public function streamRpc(
        string $repoPath,
        string $service,
        $inputStream,
        bool $isGzip = false,
        ?string $protocol = null,
        ?User $user = null,
        ?string $repoName = null,
    ): void {
        $this->assertValidService($service);

        $subcommand = str_replace('git-', '', $service);
        $command = ['git', $subcommand, '--stateless-rpc', $repoPath];
        $env = $this->buildEnv($repoPath, $repoName, $user, $protocol);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(EnvCommand::wrap($command, $env), $descriptors, $pipes, $repoPath);
        if (!\is_resource($process)) {
            throw new \RuntimeException(sprintf('Failed to execute git %s for RPC', $subcommand));
        }

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $inContext = null;
        if ($isGzip) {
            $ctx = inflate_init(\ZLIB_ENCODING_GZIP);
            if (false === $ctx) {
                throw new \RuntimeException('Failed to initialize zlib decompressor');
            }
            $inContext = $ctx;
        }
        $inBuffer = '';
        $inEof = false;

        while (true) {
            if (null !== $pipes[0] && '' === $inBuffer && !$inEof) {
                if (feof($inputStream)) {
                    $inEof = true;
                    if (null !== $inContext) {
                        $inBuffer = (string) inflate_add($inContext, '', \ZLIB_FINISH);
                    }
                } else {
                    $raw = fread($inputStream, 65536);
                    if (false === $raw || '' === $raw) {
                        $inEof = true;
                        if (null !== $inContext) {
                            $inBuffer = (string) inflate_add($inContext, '', \ZLIB_FINISH);
                        }
                    } elseif (null !== $inContext) {
                        $inBuffer = (string) inflate_add($inContext, $raw, \ZLIB_NO_FLUSH);
                    } else {
                        $inBuffer = $raw;
                    }
                }
            }

            $r = [];
            $w = [];
            $e = null;

            if (null !== $pipes[1]) {
                $r[] = $pipes[1];
            }
            if (null !== $pipes[2]) {
                $r[] = $pipes[2];
            }
            if (null !== $pipes[0]) {
                if ('' !== $inBuffer) {
                    $w[] = $pipes[0];
                } elseif ($inEof) {
                    fclose($pipes[0]);
                    $pipes[0] = null;
                }
            }

            if (empty($r) && empty($w)) {
                break;
            }

            $num = stream_select($r, $w, $e, 1, 0);
            if (false === $num) {
                break;
            }

            if (!empty($w)) {
                $written = fwrite($pipes[0], $inBuffer);
                if (false !== $written && $written > 0) {
                    $inBuffer = substr($inBuffer, $written);
                }
            }

            if (null !== $pipes[1] && \in_array($pipes[1], $r, true)) {
                $out = fread($pipes[1], 65536);
                if (false !== $out && '' !== $out) {
                    echo $out;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                } elseif (feof($pipes[1])) {
                    fclose($pipes[1]);
                    $pipes[1] = null;
                }
            }

            if (null !== $pipes[2] && \in_array($pipes[2], $r, true)) {
                fread($pipes[2], 65536);
                if (feof($pipes[2])) {
                    fclose($pipes[2]);
                    $pipes[2] = null;
                }
            }
        }

        if (null !== $pipes[0]) {
            fclose($pipes[0]);
        }
        if (null !== $pipes[1]) {
            fclose($pipes[1]);
        }
        if (null !== $pipes[2]) {
            fclose($pipes[2]);
        }

        proc_close($process);

        if ('git-receive-pack' === $service) {
            $this->ensureDefaultBranch($repoPath);
            $this->updateServerInfo($repoPath);
        }
    }

    /**
     * Ensures HEAD points to an existing branch (preferring main or master), matching Go `EnsureDefaultBranch`.
     */
    public function ensureDefaultBranch(string $repoPath): void
    {
        $headProc = GitCommand::run($repoPath, ['rev-parse', '--verify', 'HEAD']);
        if ($headProc->isSuccessful()) {
            return;
        }

        $branchProc = GitCommand::run($repoPath, ['branch', '--format=%(refname:short)']);
        if (!$branchProc->isSuccessful()) {
            return;
        }

        $branches = array_values(array_filter(array_map('trim', explode("\n", $branchProc->getOutput()))));
        if (empty($branches)) {
            return;
        }

        $target = $branches[0];
        foreach ($branches as $b) {
            if ('main' === $b || 'master' === $b) {
                $target = $b;
                break;
            }
        }

        GitCommand::run($repoPath, ['symbolic-ref', 'HEAD', new Revision('refs/heads/'.$target)]);
    }

    /**
     * Runs git update-server-info for dumb HTTP clients.
     */
    public function updateServerInfo(string $repoPath): void
    {
        GitCommand::run($repoPath, ['update-server-info']);
    }

    /**
     * @return array<string, string>
     */
    private function buildEnv(
        string $repoPath,
        ?string $repoName,
        ?User $user,
        ?string $protocol,
    ): array {
        $env = [];
        $name = $repoName ?? basename($repoPath, '.git');

        $env['SOFT_SERVE_REPO_NAME'] = $name;
        $env['SOFT_SERVE_REPO_PATH'] = $repoPath;
        $env['SOFT_SERVE_DATA_PATH'] = $this->settings->dataPath;
        $env['SOFT_SERVE_LOG_PATH'] = $this->settings->dataPath.'/log/hooks.log';

        if (null !== $user) {
            $env['SOFT_SERVE_USERNAME'] = $user->username();
        }

        if (null !== $protocol && '' !== $protocol) {
            $env['GIT_PROTOCOL'] = $protocol;
        }

        return $env;
    }

    private function assertValidService(string $service): void
    {
        if (!\in_array($service, ['git-upload-pack', 'git-receive-pack'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported Git smart service "%s"', $service));
        }
    }

    private function parseProtocolVersion(?string $protocol): int
    {
        if (null === $protocol || '' === $protocol) {
            return 0;
        }

        $version = 0;
        foreach (explode(':', $protocol) as $param) {
            if (str_starts_with($param, 'version=')) {
                $v = (int) substr($param, 8);
                if ($v > $version) {
                    $version = $v;
                }
            }
        }

        return $version;
    }
}
