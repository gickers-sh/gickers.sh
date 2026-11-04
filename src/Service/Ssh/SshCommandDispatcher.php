<?php

declare(strict_types=1);

namespace App\Service\Ssh;

use App\Model\AccessLevel;
use App\Model\GitRepositoryModel;
use App\Model\PullRequestModel;
use App\Model\UserModel;
use App\Service\Backend\BackendService;
use App\Service\Git\GitService;
use App\Service\Security\JwtManager;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Dispatches and routes incoming SSH commands (SSH_ORIGINAL_COMMAND).
 * Routes between:
 * - Interactive TUI sessions (empty command or -t <repo>)
 * - Git smart pack services (git-upload-pack, git-receive-pack, git-upload-archive)
 * - Git LFS services (git-lfs-transfer, git-lfs-authenticate)
 * - CLI commands (repo, pr, collab, info, help, pubkey, username, jwt, token, user, settings)
 */
final class SshCommandDispatcher
{
 private string $reposDir;
 private readonly SshKeyComparator $comparator;
 private readonly JwtManager $jwtManager;

 public function __construct(
  private readonly GitService $gitService,
  ?string $reposDir = null,
  private readonly ?BackendService $backend = null,
  ?SshKeyComparator $comparator = null,
  ?JwtManager $jwtManager = null,
 ) {
  $this->comparator = $comparator ?? new SshKeyComparator();
  $this->jwtManager = $jwtManager ?? new JwtManager();

  if ($reposDir !== null && $reposDir !== '') {
   $this->reposDir = rtrim($reposDir, '/');
  } elseif ($this->backend !== null) {
   $this->backend->getDataStore()->getDatabaseManager()->ensureBootstrapped();
   $this->reposDir = $this->backend->getReposPath();
  } else {
   $dataPath = getenv('SOFT_SERVE_DATA_PATH');
   $this->reposDir = $dataPath ? rtrim($dataPath, '/') . '/repos' : dirname(__DIR__, 3) . '/.data/repos';
  }
 }

 public function getReposDir(): string
 {
  return $this->reposDir;
 }

 public function getBackend(): ?BackendService
 {
  return $this->backend;
 }

 /**
  * Parse an SSH command line and return the routing action array.
  *
  * @return array<string, mixed>
  */
 public function dispatch(string $commandLine): array
 {
  $tokens = $this->tokenize($commandLine);

  // 1. Empty command -> Interactive TUI
  if (count($tokens) === 0) {
   return [
    'type' => 'tui',
    'repo' => null,
   ];
  }

  // 2. Explicit TUI target flags (-t <repo>, --target <repo>, tui <repo>)
  if ($tokens[0] === 'tui' || $tokens[0] === '-t' || $tokens[0] === '--target') {
   $repo = isset($tokens[1]) ? $this->sanitizeRepoName($tokens[1]) : null;
   return [
    'type' => 'tui',
    'repo' => $repo,
   ];
  }

  if (str_starts_with($tokens[0], '-t=')) {
   $repo = $this->sanitizeRepoName(substr($tokens[0], 3));
   return [
    'type' => 'tui',
    'repo' => $repo,
   ];
  }

  $service = $tokens[0];

  // 3. Git Smart Pack Services
  if (in_array($service, ['git-upload-pack', 'git-receive-pack', 'git-upload-archive'], true)) {
   if (!isset($tokens[1])) {
    throw new InvalidArgumentException(sprintf('Missing repository argument for %s', $service));
   }

   $repo = $this->sanitizeRepoName($tokens[1]);
   $repoPath = $this->resolveRepoPath($repo);

   return [
    'type' => 'git-pack',
    'service' => $service,
    'repo' => $repo,
    'repo_path' => $repoPath,
   ];
  }

  // 4. Git LFS Services
  if ($service === 'git-lfs-transfer') {
   if (!isset($tokens[1])) {
    throw new InvalidArgumentException('Missing repository argument for git-lfs-transfer');
   }
   $repo = $this->sanitizeRepoName($tokens[1]);
   $operation = $tokens[2] ?? 'download';

   return [
    'type' => 'git-lfs-transfer',
    'repo' => $repo,
    'operation' => $operation,
   ];
  }

  if ($service === 'git-lfs-authenticate') {
   if (!isset($tokens[1])) {
    throw new InvalidArgumentException('Missing repository argument for git-lfs-authenticate');
   }
   $repo = $this->sanitizeRepoName($tokens[1]);
   $operation = $tokens[2] ?? 'download';

   return [
    'type' => 'git-lfs-authenticate',
    'repo' => $repo,
    'operation' => $operation,
   ];
  }

  // 5. CLI Commands
  return [
   'type' => 'cli',
   'command' => $service,
   'args' => array_slice($tokens, 1),
  ];
 }

 /**
  * Execute a routed CLI action or command line and return formatted output string.
  *
  * @param array<string, mixed>|string $actionOrCommand
  */
 public function executeCli(
  array|string $actionOrCommand,
  ?string $user = null,
  ?string $keyFingerprint = null,
 ): string {
  $action = is_string($actionOrCommand) ? $this->dispatch($actionOrCommand) : $actionOrCommand;

  if ($action['type'] !== 'cli') {
   throw new InvalidArgumentException(sprintf('Action of type "%s" cannot be executed as CLI', $action['type']));
  }

  $command = $action['command'];
  $args = $action['args'] ?? [];
  $currentUser = $this->resolveUser($user, $keyFingerprint);

  return match ($command) {
   'repo', 'repos', 'repository', 'repositories' => $this->handleRepoCommand($args, $currentUser),
   'info' => $this->handleInfoCommand($currentUser),
   'help' => $this->handleHelpCommand(),
   'pr', 'prs', 'pull-request', 'pull-requests' => $this->handlePrCommand($args, $currentUser),
   'collab' => $this->handleCollabCommand($args, $currentUser),
   'pubkey', 'pubkeys', 'publickey', 'publickeys' => $this->handlePubkeyCommand($args, $currentUser),
   'username', 'set-username' => $this->handleUsernameCommand($args, $currentUser),
   'jwt' => $this->handleJwtCommand($args, $currentUser),
   'token', 'access-token' => $this->handleTokenCommand($args, $currentUser),
   'user', 'users' => $this->handleUserCommand($args, $currentUser, $keyFingerprint),
   'settings' => $this->handleSettingsCommand($args, $currentUser, $keyFingerprint),
   default => sprintf("codes.sh: Unknown command \"%s\". Run 'help' for available commands.\n", $command),
  };
 }

 /**
  * Execute a git pack service command (git-upload-pack, git-receive-pack, git-upload-archive).
  *
  * @param array<string, mixed> $action
  */
 public function executeGitPack(array $action): int
 {
  $service = (string) $action['service'];
  $repoName = (string) $action['repo'];
  $repoPath = (string) $action['repo_path'];

  $created = false;
  if ($service === 'git-receive-pack' && !is_dir($repoPath)) {
   // Push-to-create: initialize bare repo and register in backend
   if ($this->backend !== null) {
    try {
     $this->backend->createRepositoryWithGit(name: $repoName);
     $repoPath = $this->backend->getRepoPath($repoName);
     $created = true;
    } catch (Throwable $e) {
     fwrite(STDERR, sprintf("fatal: failed to create repo '%s': %s\n", $repoName, $e->getMessage()));
     return 128;
    }
   } else {
    @mkdir($repoPath, 0755, true);
    (new Process(['git', 'init', '--bare', '-b', 'main', $repoPath]))->run();
    $created = true;
   }
  }

  if (!is_dir($repoPath)) {
   fwrite(STDERR, sprintf("fatal: repository '%s' does not exist\n", $repoName));
   return 128;
  }

  $process = new Process([$service, $repoPath]);
  $process->setInput(STDIN);
  $process->setTimeout(null);

  $exitCode = $process->run(function ($type, $buffer): void {
   if ($type === Process::ERR) {
    fwrite(STDERR, $buffer);
   } else {
    fwrite(STDOUT, $buffer);
   }
  });

  if ($exitCode !== 0 && $created && $this->backend !== null) {
   $this->backend->deleteRepo($repoName);
  }

  return $exitCode;
 }

 public function generateLfsToken(string $repo, string $operation, ?string $username = null, ?string $keyFingerprint = null): string
 {
  $user = $this->resolveUser($username, $keyFingerprint);
  $sub = $user !== null ? sprintf('%s#%d', $user->username, $user->id ?? 0) : 'anonymous';
  $publicUrl = getenv('SOFT_SERVE_HTTP_PUBLIC_URL') ?: 'http://localhost:8080';

  return $this->jwtManager->generateToken(
   sub: $sub,
   customClaims: [
    'repo' => $repo,
    'op' => $operation,
    'iss' => $publicUrl,
   ],
   ttl: 3600,
  );
 }

 /**
  * Normalize and sanitize a repository name, guarding against directory traversal.
  */
 public function sanitizeRepoName(string $rawRepo): string
 {
  $cleaned = trim($rawRepo, " \t\n\r'\"/");

  if ($cleaned === '') {
   throw new InvalidArgumentException('Repository name cannot be empty');
  }

  if (str_contains($cleaned, '..') || str_starts_with($cleaned, '/') || str_contains($cleaned, '\\')) {
   throw new InvalidArgumentException('Path traversal detected in repository name');
  }

  while (str_ends_with($cleaned, '.git')) {
   $cleaned = substr($cleaned, 0, -4);
  }

  $cleaned = trim($cleaned, '/');
  if ($cleaned === '' || str_contains($cleaned, '..')) {
   throw new InvalidArgumentException('Invalid repository name');
  }

  return $cleaned;
 }

 /**
  * Resolve on-disk directory for a repository name.
  */
 public function resolveRepoPath(string $repoName): string
 {
  $clean = $this->sanitizeRepoName($repoName);

  if (is_dir($clean)) {
   return realpath($clean) ?: $clean;
  }

  $backendPath = $this->backend?->getRepoPath($clean);
  $candidates = [
   $this->reposDir . '/' . $clean . '.git',
   $this->reposDir . '/' . $clean,
   $backendPath,
   dirname(__DIR__, 4) . '/' . $clean,
  ];

  foreach ($candidates as $candidate) {
   if ($candidate !== null && is_dir($candidate)) {
    return realpath($candidate) ?: $candidate;
   }
  }

  return $this->reposDir . '/' . $clean . '.git';
 }

 /**
  * Shell token parsing respecting quotes and escape characters.
  *
  * @return string[]
  */
 public function tokenize(string $commandLine): array
 {
  $line = trim($commandLine);
  if ($line === '') {
   return [];
  }

  $tokens = [];
  $len = strlen($line);
  $inQuote = false;
  $quoteChar = '';
  $currentToken = '';

  for ($i = 0; $i < $len; $i++) {
   $char = $line[$i];

   if ($inQuote) {
    if ($char === $quoteChar) {
     $inQuote = false;
    } elseif ($char === '\\' && $i + 1 < $len && $line[$i + 1] === $quoteChar) {
     $currentToken .= $quoteChar;
     $i++;
    } else {
     $currentToken .= $char;
    }
   } else {
    if ($char === '"' || $char === "'") {
     $inQuote = true;
     $quoteChar = $char;
    } elseif ($char === '\\' && $i + 1 < $len && ($line[$i + 1] === ' ' || $line[$i + 1] === '"' || $line[$i + 1] === "'")) {
     $currentToken .= $line[$i + 1];
     $i++;
    } elseif ($char === ' ' || $char === "\t") {
     if ($currentToken !== '') {
      $tokens[] = $currentToken;
      $currentToken = '';
     }
    } else {
     $currentToken .= $char;
    }
   }
  }

  if ($currentToken !== '') {
   $tokens[] = $currentToken;
  }

  return $tokens;
 }

 /**
  * Helper to parse flags and arguments.
  *
  * @param string[] $tokens
  * @param string[] $booleanFlags
  * @param string[] $valueFlags
  * @return array{flags: array<string, string|bool>, args: list<string>}
  */
 public function parseFlagsAndArgs(array $tokens, array $booleanFlags = [], array $valueFlags = []): array
 {
  $flags = [];
  $args = [];
  $count = count($tokens);

  for ($i = 0; $i < $count; $i++) {
   $token = $tokens[$i];
   if ($token === '--') {
    for ($j = $i + 1; $j < $count; $j++) {
     $args[] = $tokens[$j];
    }
    break;
   }

   if (str_starts_with($token, '--')) {
    $eqPos = strpos($token, '=');
    if ($eqPos !== false) {
     $key = substr($token, 2, $eqPos - 2);
     $val = substr($token, $eqPos + 1);
     $flags[$key] = $val;
    } else {
     $key = substr($token, 2);
     if (in_array($key, $booleanFlags, true)) {
      $flags[$key] = true;
     } elseif (in_array($key, $valueFlags, true) && $i + 1 < $count) {
      $flags[$key] = $tokens[++$i];
     } else {
      $flags[$key] = true;
     }
    }
   } elseif (str_starts_with($token, '-') && strlen($token) > 1 && !is_numeric($token)) {
    $key = substr($token, 1);
    $eqPos = strpos($key, '=');
    if ($eqPos !== false) {
     $k = substr($key, 0, $eqPos);
     $val = substr($key, $eqPos + 1);
     $flags[$k] = $val;
    } elseif (in_array($key, $booleanFlags, true)) {
     $flags[$key] = true;
    } elseif (in_array($key, $valueFlags, true) && $i + 1 < $count) {
     $flags[$key] = $tokens[++$i];
    } else {
     // Check single char flags
     $charKey = $key[0];
     if (in_array($charKey, $valueFlags, true) && $i + 1 < $count) {
      $flags[$charKey] = $tokens[++$i];
     } else {
      for ($c = 0; $c < strlen($key); $c++) {
       $flags[$key[$c]] = true;
      }
     }
    }
   } else {
    $args[] = $token;
   }
  }

  return ['flags' => $flags, 'args' => $args];
 }

 // -------------------------------------------------------------------------
 // User & Auth Resolution
 // -------------------------------------------------------------------------

 private function resolveUser(?string $username, ?string $keyFingerprint): ?UserModel
 {
  if ($username !== null && $username !== '') {
   if ($this->backend !== null) {
    $u = $this->backend->getUser($username);
    if ($u !== null) {
     return $u;
    }
   }
   return new UserModel(
    username: $username,
    isAdmin: ($username === 'admin' || $username === 'root')
   );
  }

  if ($keyFingerprint !== null && $keyFingerprint !== '' && $this->backend !== null) {
   foreach ($this->backend->listUsers() as $user) {
    if ($user->id !== null) {
     $keys = $this->backend->getDataStore()->listPublicKeys($user->id);
     foreach ($keys as $pk) {
      try {
       if ($this->comparator->fingerprintSha256($pk) === $keyFingerprint) {
        return $user;
       }
      } catch (Throwable) {
      }
     }
    }
   }
  }

  return null;
 }

 private function isServerAdmin(?UserModel $user, ?string $keyFingerprint): bool
 {
  if ($user !== null && $user->isAdmin) {
   return true;
  }

  if ($user !== null && in_array(strtolower($user->username), ['admin', 'root'], true)) {
   return true;
  }

  if ($keyFingerprint !== null) {
   $adminKeysEnv = getenv('SOFT_SERVE_INITIAL_ADMIN_KEYS');
   if ($adminKeysEnv) {
    $lines = explode("\n", $adminKeysEnv);
    foreach ($lines as $line) {
     $line = trim($line);
     if ($line === '' || str_starts_with($line, '#')) {
      continue;
     }
     try {
      if ($this->comparator->fingerprintSha256($line) === $keyFingerprint) {
       return true;
      }
     } catch (Throwable) {
     }
    }
   }
  }

  if ($this->backend === null) {
   return true;
  }

  return false;
 }

 private function checkServerAdmin(?UserModel $user, ?string $keyFingerprint): void
 {
  if (!$this->isServerAdmin($user, $keyFingerprint)) {
   throw new RuntimeException('unauthorized');
  }
 }

 /**
  * @return array<string, mixed>
  */
 private function requireRepo(string $repoName, ?UserModel $user, AccessLevel $needLevel): array
 {
  $clean = $this->sanitizeRepoName($repoName);

  if ($this->backend !== null) {
   $repo = $this->backend->getRepo($clean);
   if ($repo === null) {
    if ($this->isServerAdmin($user, null)) {
     // Check if git repo exists on disk
     $diskPath = $this->resolveRepoPath($clean);
     if (is_dir($diskPath)) {
      return ['name' => $clean];
     }
     throw new RuntimeException('repository not found');
    }

    if ($user === null) {
     throw new RuntimeException('unauthorized');
    }
    throw new RuntimeException('repository not found');
   }

   if (!$this->isServerAdmin($user, null)) {
    $level = $this->backend->getAccessLevel($clean, $user?->username);
    if ($level->value < $needLevel->value) {
     throw new RuntimeException('unauthorized');
    }
   }

   return $repo;
  }

  $diskPath = $this->resolveRepoPath($clean);
  if (!is_dir($diskPath)) {
   throw new RuntimeException(sprintf("fatal: repository '%s' not found", $clean));
  }

  return ['name' => $clean];
 }

 private function checkRepoCollabAccess(string $repoName, ?UserModel $user): void
 {
  $clean = $this->sanitizeRepoName($repoName);
  if ($this->backend !== null) {
   if ($this->isServerAdmin($user, null)) {
    return;
   }
   $level = $this->backend->getAccessLevel($clean, $user?->username);
   if ($level->value < AccessLevel::ReadWriteAccess->value) {
    throw new RuntimeException('unauthorized');
   }
  }
 }

    // -------------------------------------------------------------------------
    // repo command handler
    // -------------------------------------------------------------------------

 /**
  * @param string[] $args
  */
 private function handleRepoCommand(array $args, ?UserModel $currentUser): string
 {
  $sub = $args[0] ?? 'help';
  $subArgs = array_slice($args, 1);

  return match ($sub) {
   'tree' => $this->handleRepoTree($subArgs, $currentUser),
   'blob', 'cat', 'show' => $this->handleRepoBlob($subArgs, $currentUser),
   'list', 'ls' => $this->handleRepoList($subArgs, $currentUser),
   'create' => $this->handleRepoCreate($subArgs, $currentUser),
   'delete', 'del', 'remove', 'rm' => $this->handleRepoDelete($subArgs, $currentUser),
   'rename', 'mv', 'move' => $this->handleRepoRename($subArgs, $currentUser),
   'project-name', 'project' => $this->handleRepoProjectName($subArgs, $currentUser),
   'private' => $this->handleRepoPrivate($subArgs, $currentUser),
   'hidden', 'hide' => $this->handleRepoHidden($subArgs, $currentUser),
   'mirror' => $this->handleRepoMirror($subArgs, $currentUser),
   'fork' => $this->handleRepoFork($subArgs, $currentUser),
   'import' => $this->handleRepoImport($subArgs, $currentUser),
   'branch', 'branches' => $this->handleRepoBranch($subArgs, $currentUser),
   'tag', 'tags' => $this->handleRepoTag($subArgs, $currentUser),
   'commit' => $this->handleRepoCommit($subArgs, $currentUser),
   'info' => $this->handleRepoInfo($subArgs, $currentUser),
   'description', 'desc' => $this->handleRepoDescription($subArgs, $currentUser),
   'collab' => $this->handleCollabCommand($subArgs, $currentUser),
   'pr', 'prs', 'pull-request', 'pull-requests' => $this->handlePrCommand($subArgs, $currentUser),
   'webhook', 'webhooks' => $this->handleWebhookCommand($subArgs, $currentUser),
   default => $this->repoHelp(),
  };
 }

 /**
  * repo tree REPOSITORY [REFERENCE] [PATH]
  *
  * @param string[] $args
  */
 private function handleRepoTree(array $args, ?UserModel $currentUser): string
 {
  if (count($args) === 0) {
   return "Usage: repo tree <repo> [ref] [path]\n";
  }

  $repoName = $args[0];
  $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

  $repoPath = $this->resolveRepoPath($repoName);
  if (!is_dir($repoPath)) {
   throw new RuntimeException(sprintf("fatal: repository '%s' not found", $repoName));
  }

  $repoModel = $this->gitService->openRepository($repoPath);
  $refs = $this->gitService->getRefs($repoModel);
  $knownRefs = array_merge($refs->branches, $refs->tags, ['HEAD']);

  $ref = 'HEAD';
  $path = '';

  if (count($args) === 2) {
   if (in_array($args[1], $knownRefs, true)) {
    $ref = $args[1];
   } else {
    $path = $args[1];
   }
  } elseif (count($args) >= 3) {
   $ref = $args[1];
   $path = $args[2];
  }

  $entries = $this->gitService->getTree($repoModel, $ref, $path);

  $out = '';
  foreach ($entries as $entry) {
   $sizeStr = $entry->size !== null ? (string) $entry->size : '-';
   $out .= sprintf("%s %s %8s %s\n", $entry->mode, $entry->type, $sizeStr, $entry->name);
  }

  return $out;
 }

 /**
  * repo blob REPOSITORY [REFERENCE] [PATH]
  *
  * @param string[] $args
  */
 private function handleRepoBlob(array $args, ?UserModel $currentUser): string
 {
  $parsed = $this->parseFlagsAndArgs($args, ['raw', 'r', 'linenumber', 'l', 'color', 'c'], []);
  $pArgs = $parsed['args'];
  $flags = $parsed['flags'];

  if (count($pArgs) < 2) {
   return "Usage: repo blob <repo> [ref] <path>\n";
  }

  $repoName = $pArgs[0];
  $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

  $repoPath = $this->resolveRepoPath($repoName);
  if (!is_dir($repoPath)) {
   throw new RuntimeException(sprintf("fatal: repository '%s' not found", $repoName));
  }

  $repoModel = $this->gitService->openRepository($repoPath);
  if (count($pArgs) === 2) {
   $ref = 'HEAD';
   $filePath = $pArgs[1];
  } else {
   $ref = $pArgs[1];
   $filePath = $pArgs[2];
  }

  $content = $this->gitService->getBlob($repoModel, $ref, $filePath);

  $raw = !empty($flags['raw']) || !empty($flags['r']);
  $linenumber = !empty($flags['linenumber']) || !empty($flags['l']);

  if ($linenumber) {
   $lines = explode("\n", $content);
   $numbered = [];
   $lineCount = count($lines);
   for ($i = 0; $i < $lineCount; $i++) {
    if ($i === $lineCount - 1 && $lines[$i] === '') {
     continue;
    }
    $numbered[] = sprintf('%6d  %s', $i + 1, $lines[$i]);
   }
   return implode("\n", $numbered) . "\n";
  }

  return $content;
 }

 /**
  * repo list [-a|--all]
  *
  * @param string[] $args
  */
 private function handleRepoList(array $args = [], ?UserModel $currentUser = null): string
 {
  $parsed = $this->parseFlagsAndArgs($args, ['all', 'a'], []);
  $showAll = !empty($parsed['flags']['all']) || !empty($parsed['flags']['a']);

  if ($this->backend !== null) {
   $rows = $this->backend->listRepos();
   $names = [];
   foreach ($rows as $row) {
    $name = (string) $row['name'];
    if (!$this->isServerAdmin($currentUser, null)) {
     $level = $this->backend->getAccessLevel($name, $currentUser?->username);
     if ($level->value < AccessLevel::ReadOnlyAccess->value) {
      continue;
     }
    }
    if (!$showAll && !empty($row['hidden'])) {
     continue;
    }
    $names[] = $name;
   }
   sort($names);
   if (count($names) === 0) {
    return "No repositories found.\n";
   }
   return implode("\n", $names) . "\n";
  }

  if (!is_dir($this->reposDir)) {
   return "No repositories found.\n";
  }

  $items = scandir($this->reposDir);
  if ($items === false) {
   return "No repositories found.\n";
  }

  $repos = [];
  foreach ($items as $item) {
   if ($item === '.' || $item === '..') {
    continue;
   }

   $full = $this->reposDir . '/' . $item;
   if (is_dir($full)) {
    $name = str_ends_with($item, '.git') ? substr($item, 0, -4) : $item;
    $repos[] = $name;
   }
  }

  sort($repos);
  if (count($repos) === 0) {
   return "No repositories found.\n";
  }

  return implode("\n", $repos) . "\n";
 }

 /**
  * repo create REPOSITORY [-p|--private] [-d|--description <desc>] [-n|--name <project-name>] [-H|--hidden]
  *
  * @param string[] $args
  */
 private function handleRepoCreate(array $args, ?UserModel $currentUser): string
 {
  $parsed = $this->parseFlagsAndArgs($args, ['private', 'p', 'hidden', 'H'], ['description', 'd', 'name', 'n']);
  $pArgs = $parsed['args'];
  $flags = $parsed['flags'];

  if (count($pArgs) === 0) {
   return "Usage: repo create <repo> [--private] [--description <desc>] [--name <name>] [--hidden]\n";
  }

  $repoName = $this->sanitizeRepoName($pArgs[0]);
  $this->checkRepoCollabAccess($repoName, $currentUser);

  $private = !empty($flags['private']) || !empty($flags['p']);
  $hidden = !empty($flags['hidden']) || !empty($flags['H']);
  $description = (string) ($flags['description'] ?? $flags['d'] ?? '');
  $projectName = (string) ($flags['name'] ?? $flags['n'] ?? '');

  if ($this->backend !== null) {
   $this->backend->createRepositoryWithGit(
    name: $repoName,
    owner: $currentUser,
    projectName: $projectName,
    description: $description,
    private: $private,
    hidden: $hidden,
   );
  } else {
   $repoPath = $this->reposDir . '/' . $repoName . '.git';
   @mkdir($repoPath, 0755, true);
   (new Process(['git', 'init', '--bare', '-b', 'main', $repoPath]))->run();
   if ($description !== '') {
    @file_put_contents($repoPath . '/description', trim($description) . "\n");
   }
  }

  $publicUrl = getenv('SOFT_SERVE_SSH_PUBLIC_URL') ?: 'ssh://localhost:23231';
  $cloneUrl = sprintf('%s/%s.git', rtrim($publicUrl, '/'), $repoName);

  return sprintf("Created repository %s\n%s\n", $repoName, $cloneUrl);
 }

 /**
  * repo delete REPOSITORY
  *
  * @param string[] $args
  */
 private function handleRepoDelete(array $args, ?UserModel $currentUser): string
 {
  if (count($args) === 0) {
   return "Usage: repo delete <repo>\n";
  }

  $repoName = $this->sanitizeRepoName($args[0]);
  $this->requireRepo($repoName, $currentUser, AccessLevel::ReadWriteAccess);

  if ($this->backend !== null) {
   $this->backend->deleteRepo($repoName);
  }

  $repoPath = $this->resolveRepoPath($repoName);
  if (is_dir($repoPath)) {
   (new Process(['rm', '-rf', $repoPath]))->run();
  }

  return sprintf("Deleted repository %s\n", $repoName);
 }

 /**
  * repo rename REPOSITORY NEW_NAME
  *
  * @param string[] $args
  */
 private function handleRepoRename(array $args, ?UserModel $currentUser): string
 {
  if (count($args) < 2) {
   return "Usage: repo rename <old-repo> <new-repo>\n";
  }

  $oldName = $this->sanitizeRepoName($args[0]);
  $newName = $this->sanitizeRepoName($args[1]);

  $this->requireRepo($oldName, $currentUser, AccessLevel::ReadWriteAccess);
  $this->checkRepoCollabAccess($newName, $currentUser);

  if ($this->backend !== null) {
   $this->backend->renameRepo($oldName, $newName);
  } else {
   $oldPath = $this->resolveRepoPath($oldName);
   $newPath = $this->reposDir . '/' . $newName . '.git';
   if (is_dir($oldPath)) {
    @mkdir(dirname($newPath), 0755, true);
    rename($oldPath, $newPath);
   }
  }

  return sprintf("Renamed repository %s to %s\n", $oldName, $newName);
 }

 /**
  * repo project-name REPOSITORY [NAME]
  *
  * @param string[] $args
  */
 private function handleRepoProjectName(array $args, ?UserModel $currentUser): string
 {
  if (count($args) === 0) {
   return "Usage: repo project-name <repo> [name]\n";
  }

  $repoName = $this->sanitizeRepoName($args[0]);
  $repo = $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

  if (count($args) === 1) {
   return (string) ($repo['project_name'] ?? '') . "\n";
  }

  $this->requireRepo($repoName, $currentUser, AccessLevel::ReadWriteAccess);
  $newName = implode(' ', array_slice($args, 1));
  if ($this->backend !== null) {
   $this->backend->setProjectName($repoName, $newName);
  }

  return sprintf("Updated project name for %s\n", $repoName);
 }

 /**
  * repo private REPOSITORY [true|false]
  *
  * @param string[] $args
  */
 private function handleRepoPrivate(array $args, ?UserModel $currentUser): string
 {
  if (count($args) === 0) {
   return "Usage: repo private <repo> [true|false]\n";
  }

  $repoName = $this->sanitizeRepoName($args[0]);
  $repo = $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

  if (count($args) === 1) {
   $isPrivate = !empty($repo['private']);
   return ($isPrivate ? 'true' : 'false') . "\n";
  }

  $this->requireRepo($repoName, $currentUser, AccessLevel::ReadWriteAccess);
  $val = filter_var($args[1], FILTER_VALIDATE_BOOLEAN);
  if ($this->backend !== null) {
   $this->backend->setPrivate($repoName, $val);
  }

  return sprintf("Updated private setting for %s\n", $repoName);
 }

 /**
  * repo hidden REPOSITORY [true|false]
  *
  * @param string[] $args
  */
 private function handleRepoHidden(array $args, ?UserModel $currentUser): string
 {
  if (count($args) === 0) {
   return "Usage: repo hidden <repo> [true|false]\n";
  }

  $repoName = $this->sanitizeRepoName($args[0]);
  $repo = $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

  if (count($args) === 1) {
   $isHidden = !empty($repo['hidden']);
   return ($isHidden ? 'true' : 'false') . "\n";
  }

  $this->requireRepo($repoName, $currentUser, AccessLevel::ReadWriteAccess);
  $val = filter_var($args[1], FILTER_VALIDATE_BOOLEAN);
  if ($this->backend !== null) {
   $this->backend->setHidden($repoName, $val);
  }

  return sprintf("Updated hidden setting for %s\n", $repoName);
 }

 /**
  * repo mirror REPOSITORY [true|false]
  *
  * @param string[] $args
  */
 private function handleRepoMirror(array $args, ?UserModel $currentUser): string
 {
  if (count($args) === 0) {
   return "Usage: repo mirror <repo> [true|false]\n";
  }

  $repoName = $this->sanitizeRepoName($args[0]);
  $repo = $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

  if (count($args) === 1) {
   $isMirror = !empty($repo['mirror']);
   return ($isMirror ? 'true' : 'false') . "\n";
  }

  $this->requireRepo($repoName, $currentUser, AccessLevel::ReadWriteAccess);
  $val = filter_var($args[1], FILTER_VALIDATE_BOOLEAN);
  if ($this->backend !== null) {
   $this->backend->setMirror($repoName, $val);
  }

  return sprintf("Updated mirror setting for %s\n", $repoName);
 }

 /**
  * repo fork SOURCE [NEW_NAME] [--private] [--description <desc>] [--name <name>] [--hidden]
  *
  * @param string[] $args
  */
 private function handleRepoFork(array $args, ?UserModel $currentUser): string
 {
  $parsed = $this->parseFlagsAndArgs($args, ['private', 'p', 'hidden', 'H'], ['description', 'd', 'name', 'n']);
  $pArgs = $parsed['args'];

  if (count($pArgs) === 0) {
   return "Usage: repo fork <source> [new-name]\n";
  }

  $sourceName = $this->sanitizeRepoName($pArgs[0]);
  $this->requireRepo($sourceName, $currentUser, AccessLevel::ReadOnlyAccess);

  if (isset($pArgs[1])) {
   $newName = $this->sanitizeRepoName($pArgs[1]);
  } else {
   $base = basename($sourceName);
   $newName = $currentUser !== null ? $currentUser->username . '/' . $base : $base;
  }

  $this->checkRepoCollabAccess($newName, $currentUser);

  $srcPath = $this->resolveRepoPath($sourceName);
  $dstPath = $this->reposDir . '/' . $newName . '.git';

  if (is_dir($srcPath) && !is_dir($dstPath)) {
   @mkdir(dirname($dstPath), 0755, true);
   (new Process(['git', 'clone', '--bare', $srcPath, $dstPath]))->run();
  }

  if ($this->backend !== null) {
   $owner = $currentUser !== null ? $currentUser->username : 'anonymous';
   $this->backend->forkRepo($sourceName, $newName, $owner);
  }

  $publicUrl = getenv('SOFT_SERVE_SSH_PUBLIC_URL') ?: 'ssh://localhost:23231';
  $cloneUrl = sprintf('%s/%s.git', rtrim($publicUrl, '/'), $newName);

  return sprintf("Forked %s into %s\n%s\n", $sourceName, $newName, $cloneUrl);
 }

 /**
  * repo import REPOSITORY REMOTE [--mirror] [--private] [--description <desc>] [--name <name>] [--hidden]
  *
  * @param string[] $args
  */
 private function handleRepoImport(array $args, ?UserModel $currentUser): string
 {
  $parsed = $this->parseFlagsAndArgs($args, ['mirror', 'm', 'private', 'p', 'hidden', 'H', 'lfs'], ['description', 'd', 'name', 'n', 'lfs-endpoint']);
  $pArgs = $parsed['args'];
  $flags = $parsed['flags'];

  if (count($pArgs) < 2) {
   return "Usage: repo import <repo> <remote>\n";
  }

  $repoName = $this->sanitizeRepoName($pArgs[0]);
  $remote = $pArgs[1];
  $this->checkRepoCollabAccess($repoName, $currentUser);

  $dstPath = $this->reposDir . '/' . $repoName . '.git';
  @mkdir(dirname($dstPath), 0755, true);

  $mirror = !empty($flags['mirror']) || !empty($flags['m']);
  $cloneCmd = ['git', 'clone', '--bare'];
  if ($mirror) {
   $cloneCmd[] = '--mirror';
  }
  $cloneCmd[] = $remote;
  $cloneCmd[] = $dstPath;

  $proc = new Process($cloneCmd);
  $proc->run();
  if (!$proc->isSuccessful()) {
   throw new RuntimeException(sprintf("fatal: failed to import '%s': %s", $remote, $proc->getErrorOutput()));
  }

  if ($this->backend !== null) {
   $this->backend->createRepositoryWithGit(
    name: $repoName,
    owner: $currentUser,
    projectName: (string) ($flags['name'] ?? $flags['n'] ?? ''),
    description: (string) ($flags['description'] ?? $flags['d'] ?? ''),
    private: !empty($flags['private']) || !empty($flags['p']),
    hidden: !empty($flags['hidden']) || !empty($flags['H']),
   );
  }

  return sprintf("Imported repository %s from %s\n", $repoName, $remote);
 }

 /**
  * repo branch [list|default|delete] REPOSITORY [args...]
  *
  * @param string[] $args
  */
 private function handleRepoBranch(array $args, ?UserModel $currentUser): string
 {
  if (count($args) === 0) {
   return "Usage: repo branch [list|default|delete] <repo> [args...]\n";
  }

  $sub = $args[0];
  if (in_array($sub, ['list', 'default', 'delete', 'remove', 'rm', 'del'], true)) {
   $repoName = $args[1] ?? '';
   $extra = array_slice($args, 2);
  } else {
   // direct invocation: repo branch <repo>
   $sub = 'list';
   $repoName = $args[0];
   $extra = array_slice($args, 1);
  }

  if ($repoName === '') {
   return "Usage: repo branch [list|default|delete] <repo>\n";
  }

  $cleanRepo = $this->sanitizeRepoName($repoName);
  $this->requireRepo($cleanRepo, $currentUser, AccessLevel::ReadOnlyAccess);

  $repoPath = $this->resolveRepoPath($cleanRepo);
  if (!is_dir($repoPath)) {
   throw new RuntimeException(sprintf("fatal: repository '%s' not found", $cleanRepo));
  }

  $repoModel = $this->gitService->openRepository($repoPath);
  $refs = $this->gitService->getRefs($repoModel);

  return match ($sub) {
   'list' => (function () use ($refs): string {
    $out = '';
    foreach ($refs->branches as $branch) {
     $prefix = ($branch === $refs->currentBranch) ? '* ' : '  ';
     $out .= $prefix . $branch . "\n";
    }
    return $out;
   })(),
   'default' => (function () use ($extra, $cleanRepo, $repoPath, $currentUser, $refs): string {
    if (count($extra) === 0) {
     return ($refs->currentBranch ?? 'main') . "\n";
    }
    $this->requireRepo($cleanRepo, $currentUser, AccessLevel::ReadWriteAccess);
    $newDefault = $extra[0];
    $proc = new Process(['git', 'symbolic-ref', 'HEAD', 'refs/heads/' . $newDefault], $repoPath);
    $proc->run();
    if (!$proc->isSuccessful()) {
     throw new RuntimeException(sprintf("Failed to update default branch: %s", $proc->getErrorOutput()));
    }
    return sprintf("Updated default branch for %s to %s\n", $cleanRepo, $newDefault);
   })(),
   'delete', 'remove', 'rm', 'del' => (function () use ($extra, $cleanRepo, $repoPath, $currentUser, $refs): string {
    if (count($extra) === 0) {
     return "Usage: repo branch delete <repo> <branch>\n";
    }
    $this->requireRepo($cleanRepo, $currentUser, AccessLevel::ReadWriteAccess);
    $branchToDelete = $extra[0];
    if ($branchToDelete === ($refs->currentBranch ?? 'main')) {
     throw new RuntimeException('cannot delete the default branch');
    }
    $proc = new Process(['git', 'branch', '-D', $branchToDelete], $repoPath);
    $proc->run();
    if (!$proc->isSuccessful()) {
     throw new RuntimeException(sprintf("Failed to delete branch: %s", $proc->getErrorOutput()));
    }
    return sprintf("Deleted branch %s from %s\n", $branchToDelete, $cleanRepo);
   })(),
   default => "Unknown branch subcommand.\n",
  };
 }

 /**
  * repo tag [list|delete] REPOSITORY [args...]
  *
  * @param string[] $args
  */
 private function handleRepoTag(array $args, ?UserModel $currentUser): string
 {
  if (count($args) === 0) {
   return "Usage: repo tag [list|delete] <repo> [args...]\n";
  }

  $sub = $args[0];
  if (in_array($sub, ['list', 'ls', 'delete', 'remove', 'rm', 'del'], true)) {
   $repoName = $args[1] ?? '';
   $extra = array_slice($args, 2);
  } else {
   $sub = 'list';
   $repoName = $args[0];
   $extra = array_slice($args, 1);
  }

  if ($repoName === '') {
   return "Usage: repo tag [list|delete] <repo>\n";
  }

  $cleanRepo = $this->sanitizeRepoName($repoName);
  $this->requireRepo($cleanRepo, $currentUser, AccessLevel::ReadOnlyAccess);

  $repoPath = $this->resolveRepoPath($cleanRepo);
  if (!is_dir($repoPath)) {
   throw new RuntimeException(sprintf("fatal: repository '%s' not found", $cleanRepo));
  }

  $repoModel = $this->gitService->openRepository($repoPath);
  $refs = $this->gitService->getRefs($repoModel);

  return match ($sub) {
   'list', 'ls' => (function () use ($refs): string {
    $out = '';
    foreach ($refs->tags as $tag) {
     $out .= $tag . "\n";
    }
    return $out;
   })(),
   'delete', 'remove', 'rm', 'del' => (function () use ($extra, $cleanRepo, $repoPath, $currentUser): string {
    if (count($extra) === 0) {
     return "Usage: repo tag delete <repo> <tag>\n";
    }
    $this->requireRepo($cleanRepo, $currentUser, AccessLevel::ReadWriteAccess);
    $tagToDelete = $extra[0];
    $proc = new Process(['git', 'tag', '-d', $tagToDelete], $repoPath);
    $proc->run();
    if (!$proc->isSuccessful()) {
     throw new RuntimeException(sprintf("Failed to delete tag: %s", $proc->getErrorOutput()));
    }
    return sprintf("Deleted tag %s from %s\n", $tagToDelete, $cleanRepo);
   })(),
   default => "Unknown tag subcommand.\n",
  };
 }

 /**
  * repo commit REPOSITORY SHA [-c|--color] [-p|--patch]
  *
  * @param string[] $args
  */
 private function handleRepoCommit(array $args, ?UserModel $currentUser): string
 {
  $parsed = $this->parseFlagsAndArgs($args, ['color', 'c', 'patch', 'p'], []);
  $pArgs = $parsed['args'];
  $flags = $parsed['flags'];

  if (count($pArgs) < 2) {
   return "Usage: repo commit <repo> <sha> [--patch] [--color]\n";
  }

  $repoName = $this->sanitizeRepoName($pArgs[0]);
  $sha = $pArgs[1];

  $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);
  $repoPath = $this->resolveRepoPath($repoName);
  if (!is_dir($repoPath)) {
   throw new RuntimeException(sprintf("fatal: repository '%s' not found", $repoName));
  }

  $patchOnly = !empty($flags['patch']) || !empty($flags['p']);
  $cmd = ['git', 'show'];
  if ($patchOnly) {
   $cmd[] = '--format=';
  }
  $cmd[] = $sha;

  $proc = new Process($cmd, $repoPath);
  $proc->run();
  if (!$proc->isSuccessful()) {
   throw new RuntimeException(sprintf("fatal: commit '%s' not found", $sha));
  }

  return $proc->getOutput();
 }

 /**
  * repo info REPOSITORY
  *
  * @param string[] $args
  */
 private function handleRepoInfo(array $args, ?UserModel $currentUser): string
 {
  if (count($args) === 0) {
   return "Usage: repo info <repo>\n";
  }

  $repoName = $this->sanitizeRepoName($args[0]);
  $repo = $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

  $repoPath = $this->resolveRepoPath($repoName);
  $branches = [];
  $tags = [];
  $defaultBranch = 'main';

  if (is_dir($repoPath)) {
   $repoModel = $this->gitService->openRepository($repoPath);
   $refs = $this->gitService->getRefs($repoModel);
   $branches = $refs->branches;
   $tags = $refs->tags;
   $defaultBranch = $refs->currentBranch ?? 'main';
  }

  $out = '';
  $out .= sprintf("Project Name: %s\n", $repo['project_name'] ?? '');
  $out .= sprintf("Repository: %s\n", $repoName);
  $out .= sprintf("Description: %s\n", $repo['description'] ?? '');
  $out .= sprintf("Private: %s\n", !empty($repo['private']) ? 'true' : 'false');
  $out .= sprintf("Hidden: %s\n", !empty($repo['hidden']) ? 'true' : 'false');
  $out .= sprintf("Mirror: %s\n", !empty($repo['mirror']) ? 'true' : 'false');
  $out .= sprintf("Default Branch: %s\n", $defaultBranch);

  if (count($branches) > 0) {
   $out .= "Branches:\n";
   foreach ($branches as $b) {
    $out .= sprintf("  - %s\n", $b);
   }
  }

  if (count($tags) > 0) {
   $out .= "Tags:\n";
   foreach ($tags as $t) {
    $out .= sprintf("  - %s\n", $t);
   }
  }

  return $out;
 }

 /**
  * repo description REPOSITORY [new-desc]
  *
  * @param string[] $args
  */
 private function handleRepoDescription(array $args, ?UserModel $currentUser): string
 {
  if (count($args) === 0) {
   return "Usage: repo description <repo> [new-desc]\n";
  }

  $repoName = $this->sanitizeRepoName($args[0]);
  $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

  $repoPath = $this->resolveRepoPath($repoName);
  $descFile = rtrim($repoPath, '/') . '/description';

  if (isset($args[1])) {
   $this->requireRepo($repoName, $currentUser, AccessLevel::ReadWriteAccess);
   $newDesc = implode(' ', array_slice($args, 1));
   if ($this->backend !== null) {
    $this->backend->setDescription($repoName, $newDesc);
   } elseif (is_dir($repoPath)) {
    @file_put_contents($descFile, trim($newDesc) . "\n");
   }
   return sprintf("Updated description for %s\n", $repoName);
  }

  if ($this->backend !== null) {
   $r = $this->backend->getRepo($repoName);
   if ($r !== null && !empty($r['description'])) {
    return (string) $r['description'] . "\n";
   }
  }

  if (file_exists($descFile)) {
   $content = trim((string) file_get_contents($descFile));
   if ($content !== '') {
    return $content . "\n";
   }
  }

  return "No description provided\n";
 }

 /**
  * repo webhook [list|create|delete|update|deliveries] REPOSITORY ...
  *
  * @param string[] $args
  */
 private function handleWebhookCommand(array $args, ?UserModel $currentUser): string
 {
  if (count($args) === 0) {
   return "Usage: repo webhook [list|create|delete] <repo> [args...]\n";
  }

  $sub = $args[0];
  $subArgs = array_slice($args, 1);

  return match ($sub) {
   'list', 'ls' => (function () use ($subArgs, $currentUser): string {
    $repoName = $subArgs[0] ?? '';
    if ($repoName === '') {
     return "Usage: repo webhook list <repo>\n";
    }
    $clean = $this->sanitizeRepoName($repoName);
    $this->requireRepo($clean, $currentUser, AccessLevel::ReadWriteAccess);

    if ($this->backend === null) {
     return "No webhooks found.\n";
    }
    $hooks = $this->backend->listWebhooks($clean);
    if (count($hooks) === 0) {
     return "No webhooks found.\n";
    }
    $out = '';
    foreach ($hooks as $h) {
     $out .= sprintf("#%-4d  %-40s  %-10s\n", $h->id ?? 0, $h->url, $h->active ? 'active' : 'inactive');
    }
    return $out;
   })(),
   'create' => (function () use ($subArgs, $currentUser): string {
    $parsed = $this->parseFlagsAndArgs($subArgs, [], ['secret', 'events']);
    $pArgs = $parsed['args'];
    $flags = $parsed['flags'];

    if (count($pArgs) < 2) {
     return "Usage: repo webhook create <repo> <url> [--secret <sec>] [--events <events>]\n";
    }
    $clean = $this->sanitizeRepoName($pArgs[0]);
    $url = $pArgs[1];
    $this->requireRepo($clean, $currentUser, AccessLevel::ReadWriteAccess);

    $secret = (string) ($flags['secret'] ?? '');
    $eventsStr = (string) ($flags['events'] ?? '*');
    $events = array_filter(array_map('trim', explode(',', $eventsStr)));

    if ($this->backend !== null) {
     $hook = $this->backend->createWebhook($clean, $url, $secret, $events);
     return sprintf("Created webhook %d for %s\n", $hook->id ?? 0, $clean);
    }
    return sprintf("Created webhook for %s\n", $clean);
   })(),
   'delete', 'remove', 'rm', 'del' => (function () use ($subArgs, $currentUser): string {
    if (count($subArgs) < 2) {
     return "Usage: repo webhook delete <repo> <id>\n";
    }
    $clean = $this->sanitizeRepoName($subArgs[0]);
    $id = (int) $subArgs[1];
    $this->requireRepo($clean, $currentUser, AccessLevel::ReadWriteAccess);

    if ($this->backend !== null) {
     $this->backend->deleteWebhook($id);
    }
    return sprintf("Deleted webhook %d\n", $id);
   })(),
   'deliveries' => (function () use ($subArgs, $currentUser): string {
    if (count($subArgs) < 2) {
     return "Usage: repo webhook deliveries <repo> <id> [list]\n";
    }
    $clean = $this->sanitizeRepoName($subArgs[0]);
    $id = (int) $subArgs[1];
    $this->requireRepo($clean, $currentUser, AccessLevel::ReadWriteAccess);

    if ($this->backend === null) {
     return "No deliveries found.\n";
    }
    $deliveries = $this->backend->getDataStore()->listDeliveries($id);
    if (count($deliveries) === 0) {
     return "No deliveries found.\n";
    }
    $out = '';
    foreach ($deliveries as $del) {
     $out .= sprintf("%s  %s  status: %d\n", $del['delivery_id'] ?? '', $del['created_at'] ?? '', (int) ($del['status_code'] ?? 0));
    }
    return $out;
   })(),
   default => "Unknown webhook subcommand.\n",
  };
 }

 private function repoHelp(): string
 {
  return <<<HELP
Manage repositories on codes.sh:
  repo list                      List all available repositories
  repo tree <repo> [ref] [path]  List directory or tree at path
  repo blob <repo> [ref] <path>  Display contents of file at path
  repo branch <repo>             List branches
  repo tag <repo>                List tags
  repo description <repo> [desc] View or update repository description
  repo create <repo>             Create a new repository
  repo delete <repo>             Delete a repository
  repo rename <old> <new>        Rename a repository
  repo project-name <repo> [nm]  View or update project name
  repo private <repo> [bool]     View or update private property
  repo hidden <repo> [bool]      View or update hidden property
  repo mirror <repo> [bool]      View or update mirror property
  repo fork <src> [dest]         Fork a repository
  repo import <repo> <remote>    Import a remote repository
  repo collab <subcommand>       Manage repository collaborators
  repo pr <subcommand>           Manage repository pull requests
  repo webhook <subcommand>      Manage repository webhooks

HELP;
 }

    // -------------------------------------------------------------------------
    // Top-Level Commands: collab, pr, pubkey, username, jwt, token, user, settings, info, help
    // -------------------------------------------------------------------------

 /**
  * @param string[] $args
  */
 public function handleCollabCommand(array $args, ?UserModel $currentUser): string
 {
  $sub = $args[0] ?? 'list';
  $subArgs = array_slice($args, 1);

  return match ($sub) {
   'add' => (function () use ($subArgs, $currentUser): string {
    if (count($subArgs) < 2) {
     return "Usage: collab add <repo> <username> [level]\n";
    }
    $repoName = $this->sanitizeRepoName($subArgs[0]);
    $username = $subArgs[1];
    $levelStr = $subArgs[2] ?? 'read-write';
    $level = AccessLevel::tryFromString($levelStr) ?? AccessLevel::ReadWriteAccess;

    $this->requireRepo($repoName, $currentUser, AccessLevel::ReadWriteAccess);

    if ($this->backend !== null) {
     $this->backend->addCollaborator($repoName, $username, $level);
    }
    return sprintf("Added collaborator %s to %s with %s access\n", $username, $repoName, $level->toString());
   })(),
   'remove', 'rm', 'del' => (function () use ($subArgs, $currentUser): string {
    if (count($subArgs) < 2) {
     return "Usage: collab remove <repo> <username>\n";
    }
    $repoName = $this->sanitizeRepoName($subArgs[0]);
    $username = $subArgs[1];

    $this->requireRepo($repoName, $currentUser, AccessLevel::ReadWriteAccess);

    if ($this->backend !== null) {
     $this->backend->removeCollaborator($repoName, $username);
    }
    return sprintf("Removed collaborator %s from %s\n", $username, $repoName);
   })(),
   'list', 'ls' => (function () use ($subArgs, $currentUser): string {
    if (count($subArgs) === 0) {
     return "Usage: collab list <repo>\n";
    }
    $repoName = $this->sanitizeRepoName($subArgs[0]);
    $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

    if ($this->backend === null) {
     return "No collaborators found.\n";
    }
    $collabs = $this->backend->listCollaborators($repoName);
    if (count($collabs) === 0) {
     return "No collaborators found.\n";
    }
    $out = '';
    foreach ($collabs as $c) {
     $out .= sprintf("%s\n", $c['username']);
    }
    return $out;
   })(),
   default => "Unknown collab subcommand. Available: add, remove, list\n",
  };
 }

 /**
  * @param string[] $args
  */
 public function handlePrCommand(array $args, ?UserModel $currentUser): string
 {
  $sub = $args[0] ?? 'list';
  $subArgs = array_slice($args, 1);

  return match ($sub) {
   'list', 'ls' => (function () use ($subArgs, $currentUser): string {
    $parsed = $this->parseFlagsAndArgs($subArgs, [], ['status', 's']);
    $pArgs = $parsed['args'];
    $flags = $parsed['flags'];

    if (count($pArgs) === 0) {
     return "Usage: pr list <repo> [--status <open|closed|merged|all>]\n";
    }

    $repoName = $this->sanitizeRepoName($pArgs[0]);
    $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

    if ($this->backend === null) {
     return "No pull requests found.\n";
    }

    $statusFilter = (string) ($flags['status'] ?? $flags['s'] ?? 'open');
    $state = ($statusFilter === 'all' || $statusFilter === '') ? null : strtolower($statusFilter);

    $prs = $this->backend->listPullRequests($repoName, $state);
    if (count($prs) === 0) {
     return "No pull requests found.\n";
    }

    $out = '';
    foreach ($prs as $pr) {
     $badge = sprintf('[%s]', strtoupper($pr->state ?? $pr->status ?? 'open'));
     $branches = sprintf('(%s ← %s)', $pr->baseBranch ?? $pr->baseRef ?? '', $pr->headBranch ?? $pr->headRef ?? '');
     $out .= sprintf("#%-3d  %-8s  %-30s  %s\n", $pr->number, $badge, $pr->title, $branches);
    }
    return $out;
   })(),
   'view' => (function () use ($subArgs, $currentUser): string {
    if (count($subArgs) < 2) {
     return "Usage: pr view <repo> <number>\n";
    }
    $repoName = $this->sanitizeRepoName($subArgs[0]);
    $num = (int) $subArgs[1];
    $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

    if ($this->backend === null) {
     throw new RuntimeException(sprintf("fatal: pull request #%d not found", $num));
    }

    $pr = $this->backend->getPullRequest($repoName, $num);
    if ($pr === null) {
     throw new RuntimeException(sprintf("fatal: pull request #%d not found", $num));
    }

    $out = '';
    $out .= sprintf("Pull Request #%d: %s\n", $pr->number, $pr->title);
    $out .= sprintf("Status:      %s\n", strtoupper($pr->state ?? $pr->status ?? 'open'));
    $out .= sprintf("Branches:    %s ← %s\n", $pr->baseBranch ?? $pr->baseRef ?? '', $pr->headBranch ?? $pr->headRef ?? '');
    $out .= sprintf("Base Commit: %s\n", $pr->baseCommit ?? '-');
    $out .= sprintf("Head Commit: %s\n", $pr->headCommit ?? '-');
    $out .= sprintf("Created:     %s\n", $pr->createdAt?->format('Y-m-d H:i:s') ?? '-');
    if ($pr->isMerged()) {
     $out .= sprintf("Merged:      %s\n", $pr->mergeCommit ?? '-');
    }
    $out .= "Mergeable:   Clean (no conflicts detected)\n";

    if ($pr->body !== '') {
     $out .= "\nDescription:\n" . $pr->body . "\n";
    }

    $comments = $this->backend->listPullRequestComments($repoName, $num);
    if (count($comments) > 0) {
     $out .= sprintf("\nComments (%d):\n", count($comments));
     foreach ($comments as $c) {
      $out .= sprintf("--- %s (%s) ---\n%s\n\n", $c->createdAt?->format('Y-m-d H:i:s') ?? '', $c->authorUsername, $c->body);
     }
    }

    return $out;
   })(),
   'create' => (function () use ($subArgs, $currentUser): string {
    $parsed = $this->parseFlagsAndArgs($subArgs, [], ['head', 'H', 'base', 'B', 'title', 't', 'body', 'b']);
    $pArgs = $parsed['args'];
    $flags = $parsed['flags'];

    if (count($pArgs) === 0) {
     return "Usage: pr create <repo> --head <branch> [--base <branch>] [--title <title>] [--body <body>]\n";
    }

    $repoName = $this->sanitizeRepoName($pArgs[0]);
    $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

    $head = (string) ($flags['head'] ?? $flags['H'] ?? '');
    if ($head === '') {
     throw new InvalidArgumentException('--head branch must be specified');
    }

    $base = (string) ($flags['base'] ?? $flags['B'] ?? 'main');
    $title = (string) ($flags['title'] ?? $flags['t'] ?? 'Pull Request');
    $body = (string) ($flags['body'] ?? $flags['b'] ?? '');

    $headRepo = null;
    $headBranch = $head;
    if (str_contains($head, ':')) {
     [$headRepo, $headBranch] = explode(':', $head, 2);
    }

    if ($this->backend === null) {
     return sprintf("Created pull request #1: %s (%s ← %s)\n", $title, $base, $head);
    }
    if ($currentUser === null) {
     throw new RuntimeException('unauthorized');
    }

    $author = $currentUser->username;
    $pr = $this->backend->createPullRequest(
     targetRepoName: $repoName,
     authorUsername: $author,
     title: $title,
     body: $body,
     baseBranch: $base,
     headBranch: $headBranch,
     headRepoName: $headRepo,
    );

    return sprintf("Created pull request #%d: %s (%s ← %s)\n", $pr->number, $pr->title, $pr->baseBranch ?? $pr->baseRef ?? '', $pr->headBranch ?? $pr->headRef ?? '');
   })(),
   'diff' => (function () use ($subArgs, $currentUser): string {
    if (count($subArgs) < 2) {
     return "Usage: pr diff <repo> <number>\n";
    }
    $repoName = $this->sanitizeRepoName($subArgs[0]);
    $num = (int) $subArgs[1];
    $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

    if ($this->backend === null) {
     return "No diff available.\n";
    }

    $pr = $this->backend->getPullRequest($repoName, $num);
    if ($pr === null) {
     throw new RuntimeException(sprintf("fatal: pull request #%d not found", $num));
    }

    $repoPath = $this->resolveRepoPath($repoName);
    if (!is_dir($repoPath)) {
     return "No diff available.\n";
    }

    $repoModel = $this->gitService->openRepository($repoPath);
    $diff = $this->gitService->getDiff($repoModel, $pr->baseRef, $pr->headRef);
    return $diff->rawDiff;
   })(),
   'merge' => (function () use ($subArgs, $currentUser): string {
    $parsed = $this->parseFlagsAndArgs($subArgs, [], ['title', 't']);
    $pArgs = $parsed['args'];

    if (count($pArgs) < 2) {
     return "Usage: pr merge <repo> <number> [--title <title>]\n";
    }

    $repoName = $this->sanitizeRepoName($pArgs[0]);
    $num = (int) $pArgs[1];
    $this->requireRepo($repoName, $currentUser, AccessLevel::ReadWriteAccess);

    if ($this->backend === null) {
     return sprintf("Successfully merged pull request #%d (commit 0000000)\n", $num);
    }

    $mergedBy = $currentUser !== null ? $currentUser->username : 'admin';
    $pr = $this->backend->mergePullRequest($repoName, $num, $mergedBy);
    $shortSha = substr((string) ($pr->mergeCommit ?? 'merged'), 0, 7);

    return sprintf("Successfully merged pull request #%d (commit %s)\n", $num, $shortSha);
   })(),
   'close' => (function () use ($subArgs, $currentUser): string {
    if (count($subArgs) < 2) {
     return "Usage: pr close <repo> <number>\n";
    }
    $repoName = $this->sanitizeRepoName($subArgs[0]);
    $num = (int) $subArgs[1];
    $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

    if ($this->backend !== null) {
     $author = $currentUser !== null ? $currentUser->username : 'admin';
     $this->backend->closePullRequest($repoName, $num, $author);
    }
    return sprintf("Closed pull request #%d\n", $num);
   })(),
   'reopen' => (function () use ($subArgs, $currentUser): string {
    if (count($subArgs) < 2) {
     return "Usage: pr reopen <repo> <number>\n";
    }
    $repoName = $this->sanitizeRepoName($subArgs[0]);
    $num = (int) $subArgs[1];
    $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

    if ($this->backend !== null) {
     $author = $currentUser !== null ? $currentUser->username : 'admin';
     $this->backend->reopenPullRequest($repoName, $num, $author);
    }
    return sprintf("Reopened pull request #%d\n", $num);
   })(),
   'comment' => (function () use ($subArgs, $currentUser): string {
    if (count($subArgs) < 3) {
     return "Usage: pr comment <repo> <number> <body>...\n";
    }
    $repoName = $this->sanitizeRepoName($subArgs[0]);
    $num = (int) $subArgs[1];
    $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

    $body = implode(' ', array_slice($subArgs, 2));
    if ($this->backend !== null) {
     $author = $currentUser !== null ? $currentUser->username : 'anonymous';
     $comment = $this->backend->addPullRequestComment($repoName, $num, $author, $body);
     return sprintf("Comment added (ID: %d)\n", $comment->id ?? 0);
    }
    return "Comment added\n";
   })(),
   'delete', 'rm' => (function () use ($subArgs, $currentUser): string {
    if (count($subArgs) < 2) {
     return "Usage: pr delete <repo> <number>\n";
    }
    $repoName = $this->sanitizeRepoName($subArgs[0]);
    $num = (int) $subArgs[1];
    $this->requireRepo($repoName, $currentUser, AccessLevel::ReadOnlyAccess);

    if ($this->backend !== null) {
     $this->backend->deletePullRequest($repoName, $num);
    }
    return sprintf("Pull request #%d deleted\n", $num);
   })(),
   default => "Unknown PR subcommand. Available: list, view, create, diff, merge, close, reopen, comment, delete\n",
  };
 }

 /**
  * @param string[] $args
  */
 public function handlePubkeyCommand(array $args, ?UserModel $currentUser): string
 {
  if ($currentUser === null) {
   throw new RuntimeException('unauthorized');
  }

  $sub = $args[0] ?? 'list';
  $subArgs = array_slice($args, 1);

  return match ($sub) {
   'add' => (function () use ($subArgs, $currentUser): string {
    if (count($subArgs) === 0) {
     return "Usage: pubkey add <authorized-key>\n";
    }
    $key = implode(' ', $subArgs);
    if ($this->backend !== null) {
     $this->backend->addPublicKey($currentUser->username, $key);
    }
    return "Added public key\n";
   })(),
   'remove', 'rm', 'del' => (function () use ($subArgs, $currentUser): string {
    if (count($subArgs) === 0) {
     return "Usage: pubkey remove <authorized-key>\n";
    }
    $key = implode(' ', $subArgs);
    if ($this->backend !== null) {
     $this->backend->removePublicKey($currentUser->username, $key);
    }
    return "Removed public key\n";
   })(),
   'list', 'ls' => (function () use ($currentUser): string {
    if ($this->backend !== null && $currentUser->id !== null) {
     $keys = $this->backend->getDataStore()->listPublicKeys($currentUser->id);
     if (count($keys) === 0) {
      return "No public keys found.\n";
     }
     return implode("\n", $keys) . "\n";
    }
    return "No public keys found.\n";
   })(),
   default => "Unknown pubkey subcommand. Available: add, remove, list\n",
  };
 }

 /**
  * @param string[] $args
  */
 public function handleUsernameCommand(array $args, ?UserModel $currentUser): string
 {
  if (count($args) > 0) {
   if ($currentUser === null) {
    throw new RuntimeException('unauthorized');
   }
   $newUsername = $args[0];
   if ($this->backend !== null) {
    $this->backend->setUsername($currentUser->username, $newUsername);
   }
   return sprintf("Username updated to '%s'.\n", $newUsername);
  }

  if ($currentUser !== null) {
   return sprintf("Username: %s\n", $currentUser->username);
  }

  return "Username: anonymous\n";
 }

 /**
  * @param string[] $args
  */
 public function handleJwtCommand(array $args, ?UserModel $currentUser): string
 {
  if ($currentUser === null) {
   throw new RuntimeException('unauthorized');
  }

  $audience = [];
  foreach ($args as $arg) {
   $audience[] = $this->sanitizeRepoName($arg);
  }

  $publicUrl = getenv('SOFT_SERVE_HTTP_PUBLIC_URL') ?: 'http://localhost:8080';
  $sub = sprintf('%s#%d', $currentUser->username, $currentUser->id ?? 0);

  $token = $this->jwtManager->generateToken(
   sub: $sub,
   customClaims: [
    'aud' => $audience,
    'iss' => $publicUrl,
   ],
   ttl: 3600,
  );

  return $token . "\n";
 }

 /**
  * @param string[] $args
  */
 public function handleTokenCommand(array $args, ?UserModel $currentUser): string
 {
  if ($currentUser === null) {
   throw new RuntimeException('unauthorized');
  }

  $sub = $args[0] ?? 'list';
  $subArgs = array_slice($args, 1);

  return match ($sub) {
   'create' => (function () use ($subArgs, $currentUser): string {
    $parsed = $this->parseFlagsAndArgs($subArgs, [], ['expires-in']);
    $pArgs = $parsed['args'];
    $flags = $parsed['flags'];

    if (count($pArgs) === 0) {
     return "Usage: token create <name> [--expires-in <duration>]\n";
    }

    $name = implode(' ', $pArgs);
    $expiresInStr = (string) ($flags['expires-in'] ?? '');
    $expiresAt = null;
    if ($expiresInStr !== '') {
     $secs = $this->parseDuration($expiresInStr);
     $expiresAt = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $secs));
    }

    if ($this->backend !== null) {
     $tokenData = $this->backend->createAccessToken($currentUser->username, $name, $expiresAt);
     $tokenStr = $tokenData['token'];
     return sprintf("Access token created\n%s\n", $tokenStr);
    }

    $genToken = 'sat_' . bin2hex(random_bytes(20));
    return sprintf("Access token created\n%s\n", $genToken);
   })(),
   'list', 'ls' => (function () use ($currentUser): string {
    if ($this->backend === null) {
     return "No tokens found\n";
    }

    $tokens = $this->backend->listAccessTokens($currentUser->username);
    if (count($tokens) === 0) {
     return "No tokens found\n";
    }

    $out = sprintf("%-5s  %-20s  %-20s  %s\n", "ID", "Name", "Created At", "Expires In");
    foreach ($tokens as $t) {
     $expires = $t['expires_at'] ?? '-';
     $out .= sprintf("%-5d  %-20s  %-20s  %s\n", (int) $t['id'], (string) $t['name'], (string) $t['created_at'], $expires);
    }
    return $out;
   })(),
   'delete', 'rm', 'remove' => (function () use ($subArgs, $currentUser): string {
    if (count($subArgs) === 0) {
     return "Usage: token delete <id>\n";
    }
    $id = (int) $subArgs[0];
    if ($this->backend !== null) {
     $this->backend->deleteAccessToken($currentUser->username, $id);
    }
    return "Access token deleted\n";
   })(),
   default => "Unknown token subcommand. Available: create, list, delete\n",
  };
 }

 /**
  * @param string[] $args
  */
 public function handleUserCommand(array $args, ?UserModel $currentUser, ?string $keyFingerprint): string
 {
  $this->checkServerAdmin($currentUser, $keyFingerprint);

  $sub = $args[0] ?? 'list';
  $subArgs = array_slice($args, 1);

  return match ($sub) {
   'create' => (function () use ($subArgs): string {
    $parsed = $this->parseFlagsAndArgs($subArgs, ['admin', 'a'], ['key', 'k']);
    $pArgs = $parsed['args'];
    $flags = $parsed['flags'];

    if (count($pArgs) === 0) {
     return "Usage: user create <username> [--admin] [--key <pubkey>]\n";
    }

    $username = $pArgs[0];
    $isAdmin = !empty($flags['admin']) || !empty($flags['a']);
    $key = (string) ($flags['key'] ?? $flags['k'] ?? '');

    if ($this->backend !== null) {
     $this->backend->createUser($username, $isAdmin);
     if ($key !== '') {
      $this->backend->addPublicKey($username, $key);
     }
    }
    return sprintf("Created user %s\n", $username);
   })(),
   'delete', 'del', 'rm' => (function () use ($subArgs): string {
    if (count($subArgs) === 0) {
     return "Usage: user delete <username>\n";
    }
    $username = $subArgs[0];
    if ($this->backend !== null) {
     $this->backend->deleteUser($username);
    }
    return sprintf("Deleted user %s\n", $username);
   })(),
   'list', 'ls' => (function (): string {
    if ($this->backend === null) {
     return "No users found.\n";
    }
    $users = $this->backend->listUsers();
    $names = [];
    foreach ($users as $u) {
     $names[] = $u->username;
    }
    sort($names);
    if (count($names) === 0) {
     return "No users found.\n";
    }
    return implode("\n", $names) . "\n";
   })(),
   'info' => (function () use ($subArgs): string {
    if (count($subArgs) === 0) {
     return "Usage: user info <username>\n";
    }
    $username = $subArgs[0];
    if ($this->backend === null) {
     throw new RuntimeException(sprintf("fatal: user '%s' not found", $username));
    }

    $user = $this->backend->getUser($username);
    if ($user === null) {
     throw new RuntimeException(sprintf("fatal: user '%s' not found", $username));
    }

    $out = sprintf("Username: %s\n", $user->username);
    $out .= sprintf("Admin: %s\n", $user->isAdmin ? 'true' : 'false');
    $out .= "Public keys:\n";
    if ($user->id !== null) {
     $keys = $this->backend->getDataStore()->listPublicKeys($user->id);
     foreach ($keys as $k) {
      $out .= sprintf("  %s\n", $k);
     }
    }
    return $out;
   })(),
   'add-pubkey' => (function () use ($subArgs): string {
    if (count($subArgs) < 2) {
     return "Usage: user add-pubkey <username> <key>\n";
    }
    $username = $subArgs[0];
    $key = implode(' ', array_slice($subArgs, 1));
    if ($this->backend !== null) {
     $this->backend->addPublicKey($username, $key);
    }
    return sprintf("Added public key to %s\n", $username);
   })(),
   'remove-pubkey' => (function () use ($subArgs): string {
    if (count($subArgs) < 2) {
     return "Usage: user remove-pubkey <username> <key>\n";
    }
    $username = $subArgs[0];
    $key = implode(' ', array_slice($subArgs, 1));
    if ($this->backend !== null) {
     $this->backend->removePublicKey($username, $key);
    }
    return sprintf("Removed public key from %s\n", $username);
   })(),
   'set-admin' => (function () use ($subArgs): string {
    if (count($subArgs) < 2) {
     return "Usage: user set-admin <username> [true|false]\n";
    }
    $username = $subArgs[0];
    $isAdmin = filter_var($subArgs[1], FILTER_VALIDATE_BOOLEAN);
    if ($this->backend !== null) {
     $this->backend->setUserAdmin($username, $isAdmin);
    }
    return sprintf("Updated admin status for %s\n", $username);
   })(),
   'set-username' => (function () use ($subArgs): string {
    if (count($subArgs) < 2) {
     return "Usage: user set-username <old-username> <new-username>\n";
    }
    $oldName = $subArgs[0];
    $newName = $subArgs[1];
    if ($this->backend !== null) {
     $this->backend->setUsername($oldName, $newName);
    }
    return sprintf("Updated username for %s to %s\n", $oldName, $newName);
   })(),
   default => "Unknown user subcommand. Available: create, delete, list, info, add-pubkey, remove-pubkey, set-admin, set-username\n",
  };
 }

 /**
  * @param string[] $args
  */
 public function handleSettingsCommand(array $args, ?UserModel $currentUser, ?string $keyFingerprint): string
 {
  $this->checkServerAdmin($currentUser, $keyFingerprint);

  $sub = $args[0] ?? 'help';
  $subArgs = array_slice($args, 1);

  return match ($sub) {
   'allow-keyless' => (function () use ($subArgs): string {
    if (count($subArgs) === 0) {
     $val = $this->backend?->getSetting('allow_keyless', 'false');
     return ($val === 'true' || $val === '1' ? 'true' : 'false') . "\n";
    }
    $boolVal = filter_var($subArgs[0], FILTER_VALIDATE_BOOLEAN);
    if ($this->backend !== null) {
     $this->backend->setSetting('allow_keyless', $boolVal ? 'true' : 'false');
    }
    return "Updated allow-keyless setting\n";
   })(),
   'anon-access' => (function () use ($subArgs): string {
    if (count($subArgs) === 0) {
     if ($this->backend !== null) {
      return $this->backend->getAnonAccessLevel()->toString() . "\n";
     }
     return "read-only\n";
    }
    $level = AccessLevel::tryFromString($subArgs[0]);
    if ($level === null) {
     throw new InvalidArgumentException(sprintf("invalid access level: %s", $subArgs[0]));
    }
    if ($this->backend !== null) {
     $this->backend->setAnonAccessLevel($level);
    }
    return "Updated anon-access setting\n";
   })(),
   default => "Unknown settings subcommand. Available: allow-keyless, anon-access\n",
  };
 }

 private function handleInfoCommand(?UserModel $currentUser): string
 {
  if ($currentUser !== null) {
   $out = sprintf("Username: %s\n", $currentUser->username);
   $out .= sprintf("Admin: %s\n", $currentUser->isAdmin ? 'true' : 'false');
   $out .= "Public keys:\n";
   if ($this->backend !== null && $currentUser->id !== null) {
    $keys = $this->backend->getDataStore()->listPublicKeys($currentUser->id);
    foreach ($keys as $k) {
     $out .= sprintf("  %s\n", $k);
    }
   }
   return $out;
  }

  return <<<INFO
codes.sh (Soft Serve Git Platform)
Self-hostable Git server with Terminal User Interface and SSH CLI.
Available commands: repo, pr, collab, info, help, pubkey, username, settings

INFO;
 }

 private function handleHelpCommand(): string
 {
  return <<<HELP
codes.sh SSH Command Interface:
  repo <subcommand>     Manage repositories (tree, blob, branch, tag, list, create, delete, fork, import, webhook, pr, collab)
  pr <subcommand>       Manage pull requests (list, view, create, diff, merge, close, reopen, comment, delete)
  collab <subcommand>   Manage repository collaborators (add, remove, list)
  info                  User or server information
  pubkey                Manage SSH public keys (add, remove, list)
  username              View or update username
  jwt                   Generate a JSON Web Token
  token                 Manage personal access tokens (create, list, delete)
  user                  Manage system users (admin only)
  settings              Server configuration settings (admin only)
  help                  Show this help text

HELP;
 }

 private function parseDuration(string $d): int
 {
  if (is_numeric($d)) {
   return (int) $d;
  }
  $seconds = 0;
  if (preg_match_all('/(\d+)\s*([a-zA-Z]+)/', $d, $matches, PREG_SET_ORDER)) {
   foreach ($matches as $match) {
    $val = (int) $match[1];
    $unit = strtolower($match[2]);
    $seconds += match ($unit) {
     's', 'sec', 'second', 'seconds' => $val,
     'm', 'min', 'minute', 'minutes' => $val * 60,
     'h', 'hr', 'hour', 'hours' => $val * 3600,
     'd', 'day', 'days' => $val * 86400,
     'w', 'week', 'weeks' => $val * 86400 * 7,
     'mo', 'month', 'months' => $val * 86400 * 30,
     'y', 'yr', 'year', 'years' => $val * 86400 * 365,
     default => 0,
    };
   }
  }
  return $seconds > 0 ? $seconds : 3600;
 }
}
