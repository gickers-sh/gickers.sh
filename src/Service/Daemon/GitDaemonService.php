<?php

declare(strict_types=1);

namespace App\Service\Daemon;

use InvalidArgumentException;
use RuntimeException;

/**
 * Service handling Git daemon protocol (TCP port 9418) handshake,
 * packet line decoding, security validation, path sanitization, and service execution.
 *
 * Implements compatibility with Soft Serve's `pkg/daemon/*`.
 *
 * @see https://git-scm.com/docs/protocol-v2
 */
class GitDaemonService
{
 public const DEFAULT_PORT = 9418;
 public const ALLOWED_COMMAND = 'git-upload-pack';
 public const ALLOWED_COMMANDS = [
  'git-upload-pack',
  'git-upload-archive',
 ];

 public const ERR_NOT_AUTHED = 'you are not authorized to do this';
 public const ERR_INVALID_REQUEST = 'invalid request';
 public const ERR_MAX_CONNECTIONS = 'too many connections, try again later';
 public const ERR_TIMEOUT = 'I/O timeout reached';
 public const ERR_SYSTEM_MALFUNCTION = 'something went wrong';
 public const ERR_INVALID_REPO = 'invalid repo';

 /**
  * Parses and validates an incoming `git://` pkt-line request.
  *
  * Format:
  * `<length><command> <path>\0host=<host>\0[\0<extraParam1>\0<extraParam2>...]`
  * e.g. `0032git-upload-pack /repo.git\0host=example.com\0`
  *
  * @param string $raw Raw packet bytes (with or without 4-hex length prefix)
  * @return array{
  *     command: string,
  *     repo: string,
  *     host: ?string,
  *     extraParams: list<string>,
  *     extraParamsMap: array<string, string>
  * }
  *
  * @throws InvalidArgumentException When packet structure, command, or security checks fail.
  */
 public function readPktLine(string $raw): array
 {
  $payload = $raw;

  // Check if 4-hex length prefix is present and strip it
  if (strlen($raw) >= 4 && preg_match('/^[0-9a-fA-F]{4}/', substr($raw, 0, 4))) {
   $pktLen = hexdec(substr($raw, 0, 4));
   if ($pktLen >= 4) {
    $payload = substr($raw, 4);
   }
  }

  $spacePos = strpos($payload, ' ');
  if ($spacePos === false) {
   throw new InvalidArgumentException(
    'Invalid git daemon request: missing space delimiter between command and repository'
   );
  }

  $command = substr($payload, 0, $spacePos);
  $rest = substr($payload, $spacePos + 1);

  if (!in_array($command, self::ALLOWED_COMMANDS, true)) {
   throw new InvalidArgumentException(
    sprintf('Unsupported daemon command "%s": git daemon only supports "git-upload-pack" and "git-upload-archive"', $command)
   );
  }

  $parts = explode("\0", $rest);
  $rawRepo = $parts[0] ?? '';

  if (str_contains($rawRepo, '..')) {
   throw new InvalidArgumentException(
    sprintf('Security error: directory traversal ("..") is not allowed in repo path: "%s"', $rawRepo)
   );
  }

  $repo = self::sanitizeRepoPath($rawRepo);
  if ($repo === '') {
   throw new InvalidArgumentException('Repository path cannot be empty');
  }

  $host = null;
  $extraParams = [];
  $extraParamsMap = [];

  for ($i = 1, $len = count($parts); $i < $len; $i++) {
   $part = trim($parts[$i]);
   if ($part === '') {
    continue;
   }

   if (str_starts_with($part, 'host=')) {
    $host = substr($part, 5);
   } else {
    $extraParams[] = $part;
    if (str_contains($part, '=')) {
     [$k, $v] = explode('=', $part, 2);
     $extraParamsMap[$k] = $v;
    }
   }
  }

  return [
   'command' => $command,
   'repo' => $repo,
   'host' => $host,
   'extraParams' => $extraParams,
   'extraParamsMap' => $extraParamsMap,
  ];
 }

 /**
  * Sanitizes a repository path by stripping leading/trailing slashes,
  * removing trailing `.git` suffixes, and preventing traversal.
  *
  * @throws InvalidArgumentException When directory traversal is detected.
  */
 public static function sanitizeRepoPath(string $repo): string
 {
  if (str_contains($repo, '..')) {
   throw new InvalidArgumentException('Directory traversal is not allowed in repository name');
  }

  $clean = trim($repo, " \t\n\r\0\x0B/");
  while (str_ends_with($clean, '.git')) {
   $clean = substr($clean, 0, -4);
   $clean = rtrim($clean, '/');
  }

  return $clean;
 }

 /**
  * Builds a Git daemon client request packet line with 4-hex length prefix.
  */
 public static function buildPacket(
  string $command,
  string $repo,
  ?string $host = null,
  array $extraParams = []
 ): string {
  $cleanRepo = '/' . ltrim($repo, '/');
  $payload = sprintf("%s %s\0host=%s\0", $command, $cleanRepo, $host ?? 'localhost');

  if (!empty($extraParams)) {
   $payload .= "\0" . implode("\0", $extraParams) . "\0";
  }

  $len = strlen($payload) + 4;
  return sprintf('%04x%s', $len, $payload);
 }

 /**
  * Formats an error message into a Git daemon error pkt-line ("ERR <message>\n").
  */
 public static function formatErrorPacket(string $message): string
 {
  $payload = sprintf("ERR %s\n", trim($message));
  $len = strlen($payload) + 4;
  return sprintf('%04x%s', $len, $payload);
 }

 /**
  * Builds the environment variables for git hooks and git protocol.
  *
  * @param string $repoName Sanitized repo name
  * @param string $repoPath Absolute bare repo path
  * @param string|null $host Host from packet
  * @param array<string, string>|list<string> $extraParams Extra parameters
  * @param string|null $dataPath Server data path
  * @return array<string, string>
  */
 public static function buildEnvironment(
  string $repoName,
  string $repoPath,
  ?string $host = null,
  array $extraParams = [],
  ?string $dataPath = null
 ): array {
  $dataPath = $dataPath ?? (getenv('SOFT_SERVE_DATA_PATH') ?: sys_get_temp_dir());
  $env = [
   'SOFT_SERVE_REPO_NAME' => $repoName,
   'SOFT_SERVE_REPO_PATH' => $repoPath,
   'SOFT_SERVE_HOST' => $host ?? 'localhost',
   'SOFT_SERVE_LOG_PATH' => $dataPath . '/log/hooks.log',
  ];

  $gitProtoParts = [];
  foreach ($extraParams as $k => $v) {
   if (is_int($k)) {
    $gitProtoParts[] = (string) $v;
   } else {
    $gitProtoParts[] = sprintf('%s=%s', $k, $v);
   }
  }

  if (!empty($gitProtoParts)) {
   $env['GIT_PROTOCOL'] = implode(':', $gitProtoParts);
  }

  return $env;
 }

 /**
  * Executes the requested service (git-upload-pack or git-upload-archive) on the bare repository.
  *
  * @param string $repoPath Path to the bare repository directory
  * @param string $service Service name ('git-upload-pack' or 'git-upload-archive')
  * @param string|null $host Host from packet
  * @param array $extraParams Extra parameters
  * @param string|null $input Client input bytes
  * @param string|null $dataPath Server data directory
  * @return array{status: int, stdout: string, stderr: string}
  */
 public function executeService(
  string $repoPath,
  string $service,
  ?string $host = null,
  array $extraParams = [],
  ?string $input = null,
  ?string $dataPath = null
 ): array {
  if (!in_array($service, self::ALLOWED_COMMANDS, true)) {
   throw new InvalidArgumentException(sprintf('Unsupported service: "%s"', $service));
  }

  if (!is_dir($repoPath)) {
   throw new InvalidArgumentException(sprintf('Repository path does not exist: "%s"', $repoPath));
  }

  $repoName = basename($repoPath, '.git');
  $env = self::buildEnvironment($repoName, $repoPath, $host, $extraParams, $dataPath);

  $subcommand = str_replace('git-', '', $service);
  $command = ['git', $subcommand, '.'];

  $descriptors = [
   0 => ['pipe', 'r'],
   1 => ['pipe', 'w'],
   2 => ['pipe', 'w'],
  ];

  $process = proc_open($command, $descriptors, $pipes, $repoPath, array_merge($_ENV, $env));
  if (!is_resource($process)) {
   throw new RuntimeException(sprintf('Failed to execute git %s', $subcommand));
  }

  if ($input !== null && $input !== '') {
   fwrite($pipes[0], $input);
  }
  fclose($pipes[0]);

  $stdout = stream_get_contents($pipes[1]);
  fclose($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[2]);

  $exitCode = proc_close($process);

  return [
   'status' => $exitCode,
   'stdout' => $stdout !== false ? $stdout : '',
   'stderr' => $stderr !== false ? $stderr : '',
  ];
 }

 /**
  * Handles an incoming client stream connection (socket or pipe resource).
  *
  * @param resource $conn Client socket or stream resource
  * @param string $reposDir Directory containing bare git repositories
  * @param callable|null $authCheck Callback (string $repo, ?string $host, string $command): bool
  * @param string|null $dataPath Server data directory
  */
 public function serveClient(
  $conn,
  string $reposDir,
  ?callable $authCheck = null,
  ?string $dataPath = null
 ): void {
  $lenBytes = fread($conn, 4);
  if ($lenBytes === false || strlen($lenBytes) < 4) {
   fwrite($conn, self::formatErrorPacket(self::ERR_INVALID_REQUEST));
   fclose($conn);
   return;
  }

  $pktLen = hexdec($lenBytes);
  if ($pktLen < 4 || $pktLen > 65520) {
   fwrite($conn, self::formatErrorPacket(self::ERR_INVALID_REQUEST));
   fclose($conn);
   return;
  }

  $remaining = $pktLen - 4;
  $payload = '';
  while ($remaining > 0 && !feof($conn)) {
   $chunk = fread($conn, $remaining);
   if ($chunk === false || $chunk === '') {
    break;
   }
   $payload .= $chunk;
   $remaining -= strlen($chunk);
  }

  try {
   $parsed = $this->readPktLine($lenBytes . $payload);
  } catch (\Throwable) {
   fwrite($conn, self::formatErrorPacket(self::ERR_INVALID_REQUEST));
   fclose($conn);
   return;
  }

  $repoName = $parsed['repo'];
  $repoPath = rtrim($reposDir, '/') . '/' . $repoName . '.git';

  $realRepos = realpath($reposDir);
  $realRepo = realpath($repoPath);

  if ($realRepos === false || $realRepo === false || !str_starts_with($realRepo, $realRepos)) {
   // Missing and private repositories get the identical anonymous response so neither is revealed
   fwrite($conn, self::formatErrorPacket(self::ERR_NOT_AUTHED));
   fclose($conn);
   return;
  }

  if ($authCheck !== null && !$authCheck($repoName, $parsed['host'], $parsed['command'])) {
   fwrite($conn, self::formatErrorPacket(self::ERR_NOT_AUTHED));
   fclose($conn);
   return;
  }

  $env = self::buildEnvironment($repoName, $repoPath, $parsed['host'], $parsed['extraParams'], $dataPath);
  $subcommand = str_replace('git-', '', $parsed['command']);
  $command = ['git', $subcommand, '.'];

  $descriptors = [
   0 => ['pipe', 'r'],
   1 => ['pipe', 'w'],
   2 => ['pipe', 'w'],
  ];

  $process = proc_open($command, $descriptors, $pipes, $repoPath, array_merge($_ENV, $env));
  if (!is_resource($process)) {
   fwrite($conn, self::formatErrorPacket(self::ERR_SYSTEM_MALFUNCTION));
   fclose($conn);
   return;
  }

  stream_set_blocking($conn, false);
  stream_set_blocking($pipes[1], false);

  while (is_resource($conn) && !feof($conn) && !feof($pipes[1])) {
   $read = [$conn, $pipes[1]];
   $write = null;
   $except = null;
   if (@stream_select($read, $write, $except, 1) > 0) {
    if (in_array($conn, $read, true)) {
     $buf = fread($conn, 8192);
     if ($buf !== false && $buf !== '') {
      fwrite($pipes[0], $buf);
     }
    }
    if (in_array($pipes[1], $read, true)) {
     $buf = fread($pipes[1], 8192);
     if ($buf !== false && $buf !== '') {
      fwrite($conn, $buf);
     }
    }
   }
  }

  fclose($pipes[0]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  proc_close($process);
  fclose($conn);
 }
}
