<?php

declare(strict_types=1);

namespace App\Service\Web;

/**
 * Service implementing Git Smart HTTP protocol (stateless RPC and reference advertisement),
 * Dumb HTTP object retrieval, and `go get` meta tag discovery.
 *
 * Implements compatibility with Soft Serve's `pkg/web/*`.
 *
 * @see https://git-scm.com/docs/http-protocol
 */
class GitHttpBackendService
{
    public const ALLOWED_SERVICES = [
        'git-upload-pack',
        'git-receive-pack',
        'git-upload-archive',
    ];

    /**
     * Encodes a payload into a Git pkt-line format with a 4-hex length prefix.
     */
    public function encodePktLine(string $payload): string
    {
        $length = strlen($payload) + 4;
        if ($length > 65520) {
            throw new \InvalidArgumentException(sprintf('Pkt-line payload too large (%d bytes, maximum is 65516 bytes)', strlen($payload)));
        }

        return sprintf('%04x%s', $length, $payload);
    }

    /**
     * Returns the Git pkt-line flush packet delimiter ("0000").
     */
    public function flushPkt(): string
    {
        return '0000';
    }

    /**
     * Handles Git Smart HTTP reference advertisement (`GET /<repo>.git/info/refs?service=...`)
     * or Dumb HTTP reference list (`GET /<repo>.git/info/refs`).
     *
     * @param string      $repoPath Absolute or relative filesystem path to the bare git repository
     * @param string|null $service  Service name ('git-upload-pack', 'git-receive-pack', 'git-upload-archive', or null for dumb HTTP)
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     *
     * @throws \InvalidArgumentException when service or repo path is invalid
     * @throws \RuntimeException         when git process execution fails
     */
    public function getInfoRefs(string $repoPath, ?string $service = null): array
    {
        if (!is_dir($repoPath)) {
            throw new \InvalidArgumentException(sprintf('Repository path does not exist: "%s"', $repoPath));
        }

        if (null !== $service && '' !== trim($service)) {
            $this->validateService($service);
        }

        // Smart HTTP ref advertisement is only supported for git-upload-pack and git-receive-pack.
        // Other services (or no service) fall back to Dumb HTTP info/refs matching Go's getInfoRefs.
        if (null === $service || !in_array($service, ['git-upload-pack', 'git-receive-pack'], true)) {
            $cmd = ['git', 'update-server-info'];
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $descriptors, $pipes, $repoPath);
            if (is_resource($proc)) {
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
            }

            $refsFile = rtrim($repoPath, '/').'/info/refs';
            $body = file_exists($refsFile) ? (string) file_get_contents($refsFile) : '';

            return [
                'status' => 200,
                'headers' => [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Cache-Control' => 'no-cache',
                ],
                'body' => $body,
            ];
        }
        $this->validateService($service);

        $subcommand = str_replace('git-', '', $service);
        $command = ['git', $subcommand, '--stateless-rpc', '--advertise-refs', $repoPath];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
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

        $header = $this->encodePktLine(sprintf("# service=%s\n", $service)).$this->flushPkt();
        $body = $header.(false !== $stdout ? $stdout : '');

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => sprintf('application/x-%s-advertisement', $service),
                'Cache-Control' => 'no-cache',
            ],
            'body' => $body,
        ];
    }

    /**
     * Handles Git Smart HTTP RPC requests (`POST /<repo>.git/git-upload-pack`, `POST /<repo>.git/git-receive-pack`,
     * or `POST /<repo>.git/git-upload-archive`).
     *
     * Decompresses gzipped body if needed, pipes body to `git <service> --stateless-rpc <repoPath>`,
     * and streams stdout back.
     *
     * @param string $repoPath    Bare repository path
     * @param string $service     Service name ('git-upload-pack', 'git-receive-pack', 'git-upload-archive')
     * @param string $requestBody Raw HTTP request body
     * @param bool   $isGzipped   Whether the request body is gzipped (Content-Encoding: gzip)
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     *
     * @throws \InvalidArgumentException when service or repo path is invalid
     * @throws \RuntimeException         on decompression or git execution failure
     */
    public function handleServiceRpc(
        string $repoPath,
        string $service,
        string $requestBody,
        bool $isGzipped = false,
    ): array {
        $this->validateService($service);

        if (!is_dir($repoPath)) {
            throw new \InvalidArgumentException(sprintf('Repository path does not exist: "%s"', $repoPath));
        }

        if ($isGzipped) {
            $decompressed = @gzdecode($requestBody);
            if (false === $decompressed) {
                throw new \RuntimeException('Failed to decompress gzipped request body');
            }
            $requestBody = $decompressed;
        }

        $subcommand = str_replace('git-', '', $service);
        $command = ['git', $subcommand, '--stateless-rpc', $repoPath];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException(sprintf('Failed to execute git %s for RPC', $subcommand));
        }

        fwrite($pipes[0], $requestBody);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if (0 !== $exitCode) {
            throw new \RuntimeException(sprintf('git %s --stateless-rpc exited with code %d: %s', $subcommand, $exitCode, $stderr));
        }

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => sprintf('application/x-%s-result', $service),
                'Cache-Control' => 'no-cache',
            ],
            'body' => false !== $stdout ? $stdout : '',
        ];
    }

    /**
     * Handles Dumb HTTP static object requests (loose objects, pack files, idx, alternates, HEAD).
     *
     * @param string $repoPath Path to the bare repository
     * @param string $subPath  Relative subpath inside repository (e.g. 'HEAD', 'objects/info/packs')
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handleDumbFile(string $repoPath, string $subPath): array
    {
        $cleanSub = ltrim($subPath, '/');
        if (str_contains($cleanSub, '..')) {
            return ['status' => 403, 'headers' => [], 'body' => 'Forbidden'];
        }

        $targetFile = rtrim($repoPath, '/').'/'.$cleanSub;
        if (!file_exists($targetFile)) {
            return ['status' => 404, 'headers' => [], 'body' => 'Not Found'];
        }

        $content = (string) file_get_contents($targetFile);
        $headers = [];

        if ('HEAD' === $cleanSub || str_starts_with($cleanSub, 'objects/info/')) {
            $headers['Content-Type'] = 'text/plain; charset=utf-8';
            $headers['Cache-Control'] = 'no-cache';
        } elseif (preg_match('#^objects/[0-9a-f]{2}/[0-9a-f]{38}$#i', $cleanSub)) {
            $headers['Content-Type'] = 'application/x-git-loose-object';
            $headers['Cache-Control'] = 'public, max-age=31536000, immutable';
        } elseif (preg_match('#^objects/pack/pack-[0-9a-f]{40}\.pack$#i', $cleanSub)) {
            $headers['Content-Type'] = 'application/x-git-packed-objects';
            $headers['Cache-Control'] = 'public, max-age=31536000, immutable';
        } elseif (preg_match('#^objects/pack/pack-[0-9a-f]{40}\.idx$#i', $cleanSub)) {
            $headers['Content-Type'] = 'application/x-git-packed-objects-toc';
            $headers['Cache-Control'] = 'public, max-age=31536000, immutable';
        } else {
            $headers['Content-Type'] = 'application/octet-stream';
            $headers['Cache-Control'] = 'no-cache';
        }

        return [
            'status' => 200,
            'headers' => $headers,
            'body' => $content,
        ];
    }

    /**
     * Renders Go get HTML with the standard `go-import` meta tag for package discovery.
     */
    public function renderGoGetHtml(
        string $host,
        string $repo,
        string $proto = 'https',
        ?string $publicUrl = null,
    ): string {
        $cleanRepo = trim($repo, '/');
        if (null !== $publicUrl && '' !== $publicUrl) {
            $parsedHost = parse_url($publicUrl, \PHP_URL_HOST);
            $cleanHost = $parsedHost ?: trim($host, '/');
            $importPath = sprintf('%s/%s', $cleanHost, $cleanRepo);
            $cloneUrl = sprintf('%s/%s.git', rtrim($publicUrl, '/'), $cleanRepo);
        } else {
            $cleanHost = trim($host, '/');
            $importPath = sprintf('%s/%s', $cleanHost, $cleanRepo);
            $cloneUrl = sprintf('%s://%s/%s.git', $proto, $cleanHost, $cleanRepo);
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta http-equiv="refresh" content="0; url=https://godoc.org/{$importPath}">
    <meta name="go-import" content="{$importPath} git {$cloneUrl}">
</head>
<body>
Redirecting to docs at <a href="https://godoc.org/{$importPath}">godoc.org/{$importPath}</a>...
</body>
</html>
HTML;
    }

    /**
     * Handles a `go-get=1` request by checking the path hierarchy for the closest readable repository.
     *
     * @param string   $requestedRepo       Raw repository path from URL
     * @param string   $publicUrl           Server public URL
     * @param callable $repoReadableChecker Callback (string $repo): bool
     *
     * @return array{status: int, headers: array<string, string>, body: string}|null Returns response array or null if not found
     */
    public function handleGoGet(
        string $requestedRepo,
        string $publicUrl,
        callable $repoReadableChecker,
    ): ?array {
        $repo = trim($requestedRepo, '/');
        while ('' !== $repo && '.' !== $repo && '/' !== $repo) {
            if ($repoReadableChecker($repo)) {
                $host = parse_url($publicUrl, \PHP_URL_HOST) ?: 'localhost';
                $html = $this->renderGoGetHtml($host, $repo, 'https', $publicUrl);

                return [
                    'status' => 200,
                    'headers' => [
                        'Content-Type' => 'text/html; charset=utf-8',
                    ],
                    'body' => $html,
                ];
            }
            $parent = dirname($repo);
            if ($parent === $repo || '.' === $parent) {
                break;
            }
            $repo = $parent;
        }

        return null;
    }

    /**
     * Parses an HTTP Authorization header.
     *
     * Supports:
     * - `Basic <base64>` (username/password or token)
     * - `Token <token>`
     * - `Bearer <jwt>`
     *
     * @return array{
     *     type: 'basic'|'token'|'bearer',
     *     username?: string,
     *     password?: string,
     *     token?: string
     * }|null
     */
    public function parseAuthHeader(?string $authHeader): ?array
    {
        if (null === $authHeader || '' === trim($authHeader)) {
            return null;
        }

        $parts = explode(' ', trim($authHeader), 2);
        if (2 !== count($parts)) {
            return null;
        }

        $scheme = strtolower($parts[0]);
        $credential = trim($parts[1]);

        return match ($scheme) {
            'basic' => (static function () use ($credential) {
                $decoded = base64_decode($credential, true);
                if (false === $decoded || !str_contains($decoded, ':')) {
                    return null;
                }
                [$user, $pass] = explode(':', $decoded, 2);

                return ['type' => 'basic', 'username' => $user, 'password' => $pass];
            })(),
            'token' => ['type' => 'token', 'token' => $credential],
            'bearer' => ['type' => 'bearer', 'token' => $credential],
            default => null,
        };
    }

    /**
     * Returns standard authentication challenge headers for Git and Git LFS.
     *
     * @return array<string, string>
     */
    public function getAuthChallengeHeaders(): array
    {
        return [
            'WWW-Authenticate' => 'Basic realm="Git" charset="UTF-8", Token, Bearer',
            'LFS-Authenticate' => 'Basic realm="Git LFS" charset="UTF-8", Token, Bearer',
        ];
    }

    private function validateService(string $service): void
    {
        if (!in_array($service, self::ALLOWED_SERVICES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid git service: "%s". Expected one of: %s', $service, implode(', ', self::ALLOWED_SERVICES)));
        }
    }
}
