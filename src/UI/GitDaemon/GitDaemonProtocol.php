<?php

declare(strict_types=1);

namespace App\UI\GitDaemon;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Repo\Domain\RepoName;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\Shared\Infrastructure\Git\PktLine;

/**
 * Socket-independent git:// daemon protocol handler.
 *
 * Mirrors Go `pkg/daemon/daemon.go#handleClient` + `pkg/git/service.go#gitServiceHandler`:
 * it parses the first pkt-line request, authorizes an anonymous read, then runs
 * `git upload-pack`/`upload-archive` against the bare repository, pumping the
 * client socket and git's stdout *and stderr* in both directions.
 *
 * The client-facing error strings are byte-for-byte identical to Go
 * `pkg/git/errors.go`; every domain failure is surfaced to the caller as an
 * `ERR <message>` pkt-line followed by a flush, exactly like `git.WritePktlineErr`.
 */
final class GitDaemonProtocol
{
    /** @see codes.sh.src/pkg/git/errors.go */
    public const ERR_NOT_AUTHED = 'you are not authorized to do this';
    public const ERR_SYSTEM_MALFUNCTION = 'something went wrong';
    public const ERR_INVALID_REPO = 'invalid repo';
    public const ERR_INVALID_REQUEST = 'invalid request';
    public const ERR_MAX_CONNECTIONS = 'too many connections, try again later';
    public const ERR_TIMEOUT = 'I/O timeout reached';

    /**
     * The git daemon is read-only: it only serves `upload-pack` (fetch/clone) and
     * `upload-archive`. `git-receive-pack` and everything else is an invalid request
     * (Go `handleClient` service switch).
     *
     * @var list<string>
     */
    private const ALLOWED_SERVICES = ['git-upload-pack', 'git-upload-archive'];

    private const READ_CHUNK = 32768;

    public function __construct(
        private readonly ServerSettings $settings,
        private readonly AccessGuard $accessGuard,
    ) {}

    /**
     * Reads one request from $in, authorizes it and streams the git service over
     * the three handles. $err defaults to $out because the git:// transport is a
     * single bidirectional socket (Go wires Stdin/Stdout/Stderr all to the conn).
     *
     * @param resource  $in
     * @param resource  $out
     * @param resource|null $err
     */
    public function handle($in, $out, $err = null): void
    {
        $err ??= $out;

        try {
            $plan = $this->resolve($this->readRequestLine($in));
            $this->execGit($plan, $in, $out, $err);
        } catch (DaemonFailure $failure) {
            @fwrite($out, self::errorPacket($failure->getMessage()));
        }
    }

    /**
     * Parses + authorizes a full request pkt-line (length prefix included) and
     * returns the resolved execution plan. Pure: no sockets, no process spawn.
     *
     * @return array{service: string, name: string, host: string, repoPath: string, argv: list<string>, env: array<string, string>}
     *
     * @throws DaemonFailure with {@see ERR_INVALID_REQUEST} or {@see ERR_NOT_AUTHED}
     */
    public function resolve(string $requestLine): array
    {
        $request = $this->parse($requestLine);
        $this->authorize($request['name']);

        $repoPath = $this->repoPath($request['name']);

        return [
            'service' => $request['service'],
            'name' => $request['name'],
            'host' => $request['host'],
            'repoPath' => $repoPath,
            'argv' => $this->buildArgv($request['service']),
            'env' => $this->buildEnv($request['name'], $repoPath, $request['host'], $request['extraParams']),
        ];
    }

    /**
     * Decodes the first pkt-line into its request parts (Go `handleClient` split
     * logic). Does not touch the repository or access control.
     *
     * @return array{service: string, path: string, name: string, host: string, extraParams: array<string, string>}
     *
     * @throws DaemonFailure {@see ERR_INVALID_REQUEST}
     */
    public function parse(string $requestLine): array
    {
        try {
            $packet = PktLine::parse($requestLine);
        } catch (\InvalidArgumentException) {
            throw new DaemonFailure(self::ERR_INVALID_REQUEST);
        }
        if ('data' !== $packet['type']) {
            throw new DaemonFailure(self::ERR_INVALID_REQUEST);
        }

        $payload = $packet['payload'];
        $space = strpos($payload, ' ');
        if (false === $space) {
            throw new DaemonFailure(self::ERR_INVALID_REQUEST);
        }

        $service = substr($payload, 0, $space);
        if (!\in_array($service, self::ALLOWED_SERVICES, true)) {
            throw new DaemonFailure(self::ERR_INVALID_REQUEST);
        }

        // opts := bytes.SplitN(rest, {0}, 3); len(opts) < 2 is invalid.
        $parts = explode("\0", substr($payload, $space + 1));
        if (\count($parts) < 2) {
            throw new DaemonFailure(self::ERR_INVALID_REQUEST);
        }

        $path = $parts[0];
        $host = $parts[1];
        if (str_starts_with($host, 'host=')) {
            $host = substr($host, 5);
        }

        $extraParams = [];
        foreach (\array_slice($parts, 2) as $option) {
            if ('' === $option) {
                continue;
            }
            $kv = explode('=', $option, 2);
            if (2 !== \count($kv)) {
                // Go logs and skips malformed "k=v" options.
                continue;
            }
            $extraParams[$kv[0]] = $kv[1];
        }

        return [
            'service' => $service,
            'path' => $path,
            // Go: name := utils.SanitizeRepo(opts[0]); RepoName::sanitize is that fixed point.
            'name' => RepoName::sanitize($path),
            'host' => $host,
            'extraParams' => $extraParams,
        ];
    }

    /** Formats a client-facing error pkt-line + flush, like Go `git.WritePktlineErr`. */
    public static function errorPacket(string $message): string
    {
        return PktLine::encode('ERR ' . $message . "\n") . PktLine::flush();
    }

    /**
     * Anonymous read authorization. Every rejection — keyless disabled, missing repo,
     * private repo, invalid name, or path escaping the repos root — collapses into the
     * single {@see ERR_NOT_AUTHED} answer so a caller cannot probe private-repo existence
     * (Go `handleClient`: `AllowKeyless` + `Readable(ctx, name, nil) == nil` + `EnsureWithin`).
     *
     * @throws DaemonFailure {@see ERR_NOT_AUTHED}
     */
    private function authorize(string $name): void
    {
        if (!$this->settings->allowKeyless) {
            throw new DaemonFailure(self::ERR_NOT_AUTHED);
        }

        try {
            $this->accessGuard->assertRepo(null, RepoName::fromInput($name), AccessLevel::ReadOnlyAccess);
        } catch (\Throwable) {
            throw new DaemonFailure(self::ERR_NOT_AUTHED);
        }

        $this->assertWithinReposRoot($name);
    }

    /**
     * Confirms `<repos>/<name>.git` resolves inside the repos root.
     *
     * Deviation from Go `EnsureWithin` (which uses `filepath.Abs`): we use `realpath`
     * and require a trailing directory separator on the prefix, closing the legacy
     * `realpath` prefix bug where `/data/repos-evil` matched `/data/repos`.
     *
     * @throws DaemonFailure {@see ERR_NOT_AUTHED}
     */
    private function assertWithinReposRoot(string $name): void
    {
        $realRepos = realpath($this->settings->reposPath());
        $realRepo = realpath($this->repoPath($name));

        if (
            false === $realRepos || false === $realRepo
            || !str_starts_with($realRepo, $realRepos . \DIRECTORY_SEPARATOR)
        ) {
            throw new DaemonFailure(self::ERR_NOT_AUTHED);
        }
    }

    /** `<repos>/<name>.git` (Go `filepath.Join(reposDir, name+".git")`). */
    private function repoPath(string $name): string
    {
        return $this->settings->reposPath() . '/' . $name . '.git';
    }

    /**
     * Exactly the Go argv from `gitServiceHandler`: partial-clone filter on, push
     * options advertised, LFS filters neutralized, then the service name and ".".
     *
     * @return list<string>
     */
    private function buildArgv(string $service): array
    {
        return [
            'git',
            '-c',
            'uploadpack.allowFilter=true',
            '-c',
            'receive.advertisePushOptions=true',
            '-c',
            'filter.lfs.required=',
            '-c',
            'filter.lfs.smudge=',
            '-c',
            'filter.lfs.clean=',
            // svc.Name() == strings.TrimPrefix(service, "git-").
            substr($service, 4),
            '.',
        ];
    }

    /**
     * Hook/protocol environment overlay (Go `handleClient` envs). GIT_PROTOCOL is only
     * added when the client advertised extra params.
     *
     * @param array<string, string> $extraParams
     *
     * @return array<string, string>
     */
    private function buildEnv(string $name, string $repoPath, string $host, array $extraParams): array
    {
        $env = [
            'SOFT_SERVE_REPO_NAME' => $name,
            'SOFT_SERVE_REPO_PATH' => $repoPath,
            'SOFT_SERVE_HOST' => $host,
            'SOFT_SERVE_LOG_PATH' => $this->settings->dataPath . '/log/hooks.log',
        ];

        if ([] !== $extraParams) {
            $proto = [];
            foreach ($extraParams as $key => $value) {
                $proto[] = $key . '=' . $value;
            }
            $env['GIT_PROTOCOL'] = implode(':', $proto);
        }

        return $env;
    }

    /**
     * Spawns the git service and pumps bytes bidirectionally until git exits,
     * forwarding git's stderr to the client just like Go (Stderr: c).
     *
     * @param array{service: string, name: string, host: string, repoPath: string, argv: list<string>, env: array<string, string>} $plan
     * @param resource $in
     * @param resource $out
     * @param resource $err
     *
     * @throws DaemonFailure {@see ERR_SYSTEM_MALFUNCTION} when git cannot be started
     */
    private function execGit(array $plan, $in, $out, $err): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(
            $plan['argv'],
            $descriptors,
            $pipes,
            $plan['repoPath'],
            $this->childEnvironment($plan['env']),
        );
        if (!\is_resource($process)) {
            throw new DaemonFailure(self::ERR_SYSTEM_MALFUNCTION);
        }

        [$stdin, $stdout, $stderr] = [$pipes[0], $pipes[1], $pipes[2]];
        stream_set_blocking($in, false);
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $stdinOpen = true;
        while (true) {
            $status = proc_get_status($process);

            $read = [];
            if ($stdinOpen && \is_resource($in) && !feof($in)) {
                $read[] = $in;
            }
            if (\is_resource($stdout) && !feof($stdout)) {
                $read[] = $stdout;
            }
            if (\is_resource($stderr) && !feof($stderr)) {
                $read[] = $stderr;
            }

            if ([] === $read) {
                break;
            }

            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 1);
            if (false === $ready) {
                break;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, self::READ_CHUNK);

                if ($stream === $in) {
                    if ('' === $chunk || false === $chunk) {
                        if (feof($in) && $stdinOpen) {
                            fclose($stdin);
                            $stdinOpen = false;
                        }
                        continue;
                    }
                    if ($stdinOpen) {
                        @fwrite($stdin, $chunk);
                    }
                    continue;
                }

                // git stdout and stderr both flow back to the client.
                if ('' !== $chunk && false !== $chunk) {
                    @fwrite($stream === $stdout ? $out : $err, $chunk);
                }
            }

            if (!$status['running'] && feof($stdout) && feof($stderr)) {
                break;
            }
        }

        if ($stdinOpen) {
            @fclose($stdin);
        }
        @fclose($stdout);
        @fclose($stderr);
        // Deviation from Go `handleClient`, which calls `d.fatal` (an ERR pkt-line) on a
        // non-zero service exit: once git has streamed bytes we never append ERR, which
        // would corrupt an already-sent pack. Spawn failure is the only ERR path (above).
        proc_close($process);
    }

    /**
     * Reads the first pkt-line (its 4-hex length prefix + payload) off the stream.
     *
     * @param resource $in
     *
     * @throws DaemonFailure {@see ERR_INVALID_REQUEST} or {@see ERR_TIMEOUT}
     */
    private function readRequestLine($in): string
    {
        $header = $this->readExactly($in, 4);
        if (null === $header || !ctype_xdigit($header)) {
            throw new DaemonFailure(self::ERR_INVALID_REQUEST);
        }

        $declared = (int) hexdec($header);
        if ($declared < 4) {
            // Flush/delim/response-end or a bogus short header cannot be a request.
            throw new DaemonFailure(self::ERR_INVALID_REQUEST);
        }

        $payloadLength = $declared - 4;
        if (0 === $payloadLength) {
            return $header;
        }

        $payload = $this->readExactly($in, $payloadLength);
        if (null === $payload) {
            throw new DaemonFailure(self::ERR_INVALID_REQUEST);
        }

        return $header . $payload;
    }

    /**
     * Reads exactly $length bytes, returning null on clean EOF and throwing
     * {@see ERR_TIMEOUT} when the idle read deadline fires mid-request.
     *
     * @param resource $stream
     *
     * @throws DaemonFailure {@see ERR_TIMEOUT}
     */
    private function readExactly($stream, int $length): ?string
    {
        $buffer = '';
        while (\strlen($buffer) < $length) {
            if (!\is_resource($stream) || feof($stream)) {
                return null;
            }

            $chunk = fread($stream, $length - \strlen($buffer));
            if ('' === $chunk || false === $chunk) {
                $meta = @stream_get_meta_data($stream);
                if (\is_array($meta) && !empty($meta['timed_out'])) {
                    throw new DaemonFailure(self::ERR_TIMEOUT);
                }

                return null;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * git needs the inherited PATH/HOME etc.; proc_open with an explicit env array
     * would otherwise replace the whole environment. We copy the current process
     * environment from the superglobals (no getenv/putenv in src/) and overlay the
     * computed SOFT_SERVE_* / GIT_PROTOCOL variables on top (Go: os.Environ()+Env).
     *
     * @param array<string, string> $overlay
     *
     * @return array<string, string>
     */
    private function childEnvironment(array $overlay): array
    {
        $env = [];
        foreach ($_SERVER as $key => $value) {
            if (\is_string($value)) {
                $env[$key] = $value;
            }
        }
        foreach ($_ENV as $key => $value) {
            if (\is_string($value)) {
                $env[$key] = $value;
            }
        }

        return array_merge($env, $overlay);
    }
}

/**
 * A git:// daemon request was rejected. Its message is one of the Go
 * `pkg/git/errors.go` client strings and is emitted verbatim inside an
 * `ERR <message>` pkt-line. Co-located with {@see GitDaemonProtocol} so the
 * daemon UI stays a two-file unit.
 */
final class DaemonFailure extends \RuntimeException {}
