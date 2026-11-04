<?php

declare(strict_types=1);

namespace App\Service\Backend;

use App\Model\AccessLevel;
use App\Model\PullRequestCommentModel;
use App\Model\PullRequestModel;
use App\Model\UserModel;
use App\Model\WebhookModel;
use App\Service\Store\DataStore;
use App\Service\Webhook\WebhookDispatcher;
use Symfony\Component\Process\Process;

/**
 * BackendService is the domain façade coordinating DataStore, AccessControl,
 * User & Repo lifecycle, Fork tracking, and Pull Requests.
 * Matches pkg/backend in Soft Serve.
 */
class BackendService
{
 public function __construct(
  private readonly DataStore $dataStore,
  private readonly ?WebhookDispatcher $webhookDispatcher = null,
 ) {}

 public function getDataStore(): DataStore
 {
  return $this->dataStore;
 }

 public function getDataPath(): string
 {
  return $this->dataStore->getDatabaseManager()->getDataPath();
 }

 public function getReposPath(): string
 {
  return $this->dataStore->getDatabaseManager()->getReposPath();
 }

 public function getRepoPath(string $name): string
 {
  $clean = trim($name, '/');
  if (str_ends_with($clean, '.git')) {
   $clean = substr($clean, 0, -4);
  }
  return $this->getReposPath() . '/' . $clean . '.git';
 }

    // -------------------------------------------------------------------------
    // Access Control
    // -------------------------------------------------------------------------

 /**
  * Resolves the access level of a user for a repository.
  */
 public function getAccessLevel(string $repoName, ?string $username): AccessLevel
 {
  $user = null;
  if ($username !== null && $username !== '') {
   $user = $this->dataStore->findUserByUsername($username);
   if ($user !== null && $user->isAdmin) {
    return AccessLevel::AdminAccess;
   }
  }

  $repo = $this->dataStore->findRepoByName($repoName);
  if ($repo === null) {
   if ($user !== null) {
    return AccessLevel::ReadWriteAccess;
   }
   return $this->getAnonAccessLevel();
  }

  // Repo owner has AdminAccess
  if ($user !== null && (int) $repo['user_id'] === $user->id) {
   return AccessLevel::AdminAccess;
  }

  // Collaborator check
  if ($user !== null && $user->id !== null) {
   $collabAccess = $this->dataStore->getCollaborator((int) $repo['id'], $user->id);
   if ($collabAccess !== null) {
    $isPrivate = (bool) ($repo['private'] ?? false);
    $anon = $this->getAnonAccessLevel();
    if (!$isPrivate && $anon->value > $collabAccess->value) {
     return $anon;
    }
    return $collabAccess;
   }
  }

  // Private repo restriction
  if ((bool) ($repo['private'] ?? false)) {
   return AccessLevel::NoAccess;
  }

  // Public repo
  if ($user === null) {
   return $this->getAnonAccessLevel();
  }

  return AccessLevel::ReadOnlyAccess;
 }

 public function getAnonAccessLevel(): AccessLevel
 {
  $setting = $this->dataStore->getSetting('anon_access');
  if ($setting === null || $setting === '') {
   return AccessLevel::ReadOnlyAccess;
  }

  return AccessLevel::tryFromString($setting) ?? AccessLevel::ReadOnlyAccess;
 }

 public function setAnonAccessLevel(AccessLevel $level): void
 {
  $this->dataStore->setSetting('anon_access', $level->toString());
 }

 // -------------------------------------------------------------------------
 // Users
 // -------------------------------------------------------------------------

 public function createUser(string $username, bool $isAdmin = false, ?string $password = null): UserModel
 {
  return $this->dataStore->createUser($username, $isAdmin, $password);
 }

 public function getUser(string $username): ?UserModel
 {
  return $this->dataStore->findUserByUsername($username);
 }

 public function getUserByUsername(string $username): ?UserModel
 {
  return $this->getUser($username);
 }

 public function getUserById(int $id): ?UserModel
 {
  return $this->dataStore->findUserById($id);
 }

 public function getUserByPublicKey(string $publicKey): ?UserModel
 {
  return $this->dataStore->findUserByPublicKey($publicKey);
 }

 public function addPublicKey(string $username, string $publicKey): void
 {
  $user = $this->getUser($username);
  if ($user === null || $user->id === null) {
   throw new \InvalidArgumentException(sprintf('User "%s" not found', $username));
  }

  $this->dataStore->addPublicKey($user->id, $publicKey);
 }

 public function removePublicKey(string $username, string $publicKey): void
 {
  $user = $this->getUser($username);
  if ($user === null || $user->id === null) {
   throw new \InvalidArgumentException(sprintf('User "%s" not found', $username));
  }

  $this->dataStore->removePublicKey($user->id, $publicKey);
 }

 /**
  * @return list<UserModel>
  */
 public function listUsers(): array
 {
  return $this->dataStore->listUsers();
 }

 public function deleteUser(string $username): bool
 {
  $user = $this->getUser($username);
  if ($user === null || $user->id === null) {
   return false;
  }

  return $this->dataStore->deleteUser($user->id);
 }

 // -------------------------------------------------------------------------
 // Repositories & Forks
 // -------------------------------------------------------------------------

 public function createRepo(
  string $name,
  string $projectName,
  string $description,
  bool $private,
  bool $mirror,
  bool $hidden,
  string $ownerUsername,
 ): int {
  $owner = $this->getUser($ownerUsername);
  if ($owner === null || $owner->id === null) {
   throw new \InvalidArgumentException(sprintf('Owner user "%s" not found', $ownerUsername));
  }

  return $this->dataStore->createRepo(
   name: $name,
   projectName: $projectName,
   description: $description,
   private: $private,
   mirror: $mirror,
   hidden: $hidden,
   userId: $owner->id,
  );
 }

 /**
  * Creates a bare Git repository on disk (.data/repos/<name>.git) and registers it in SQLite,
  * matching Backend.CreateRepository in pkg/backend/repo.go.
  */
 public function createRepositoryWithGit(
  string $name,
  ?UserModel $owner = null,
  string $projectName = '',
  string $description = '',
  bool $private = false,
  bool $mirror = false,
  bool $hidden = false,
 ): array {
  $cleanName = strtolower(trim($name, " \t\n\r/"));
  if (str_ends_with($cleanName, '.git')) {
   $cleanName = substr($cleanName, 0, -4);
  }
  if ($cleanName === '' || str_contains($cleanName, '..')) {
   throw new \InvalidArgumentException('Invalid repository name');
  }

  if ($this->getRepo($cleanName) !== null) {
   throw new \RuntimeException(sprintf('Repository "%s" already exists', $cleanName));
  }

  $userId = $owner?->id;
  if ($userId === null) {
   // Fallback to first admin user (ID 1), matching defaultAdminUserID in pkg/backend/repo.go
   $admin = $this->getUser('admin') ?? $this->getUserById(1);
   if ($admin === null) {
    $admin = $this->createUser('admin', isAdmin: true);
   }
   $userId = (int) $admin->id;
  }

  if ($projectName === '') {
   $pos = strrpos($cleanName, '/');
   $projectName = $pos !== false ? substr($cleanName, $pos + 1) : $cleanName;
  }

  $rp = $this->getRepoPath($cleanName);
  $parentDir = dirname($rp);
  if (!is_dir($parentDir)) {
   @mkdir($parentDir, 0755, true);
  }
  if (!is_dir($rp)) {
   @mkdir($rp, 0755, true);
   $proc = new \Symfony\Component\Process\Process(['git', 'init', '--bare', '-b', 'main', $rp]);
   $proc->run();
   if (!$proc->isSuccessful()) {
    $procFallback = new \Symfony\Component\Process\Process(['git', 'init', '--bare', $rp]);
    $procFallback->run();
   }
  }

  @file_put_contents($rp . '/description', $description);
  if (!$private) {
   @file_put_contents($rp . '/git-daemon-export-ok', '');
  }

  $this->dataStore->createRepo(
   name: $cleanName,
   projectName: $projectName,
   description: $description,
   private: $private,
   mirror: $mirror,
   hidden: $hidden,
   userId: $userId,
  );

  return $this->getRepo($cleanName) ?? [
   'name' => $cleanName,
   'project_name' => $projectName,
   'description' => $description,
   'private' => $private ? 1 : 0,
   'mirror' => $mirror ? 1 : 0,
   'hidden' => $hidden ? 1 : 0,
   'user_id' => $userId,
  ];
 }

 public function getRepo(string $name): ?array
 {
  return $this->dataStore->findRepoByName($name);
 }

 public function getRepository(string $name): ?array
 {
  return $this->getRepo($name);
 }

 public function getRepoById(int $id): ?array
 {
  return $this->dataStore->findRepoById($id);
 }

 /**
  * @return list<array>
  */
 public function listRepos(?string $username = null): array
 {
  $all = $this->dataStore->listRepos();
  if ($username === null) {
   return $all;
  }

  $user = $this->getUser($username);
  if ($user === null || $user->id === null) {
   return [];
  }

  return array_values(array_filter($all, fn($r) => (int) $r['user_id'] === $user->id));
 }

 /**
  * @return list<array>
  */
 public function listRepositories(?string $username = null): array
 {
  return $this->listRepos($username);
 }

 public function deleteRepo(string $name): bool
 {
  $repo = $this->getRepo($name);
  if ($repo === null) {
   return false;
  }

  return $this->dataStore->deleteRepo((int) $repo['id']);
 }

 public function forkRepo(string $sourceRepoName, string $forkName, string $ownerUsername): int
 {
  $source = $this->getRepo($sourceRepoName);
  if ($source === null) {
   throw new \InvalidArgumentException(sprintf('Source repo "%s" not found', $sourceRepoName));
  }

  $owner = $this->getUser($ownerUsername);
  if ($owner === null || $owner->id === null) {
   throw new \InvalidArgumentException(sprintf('Fork owner "%s" not found', $ownerUsername));
  }

  return $this->dataStore->forkRepo(
   sourceRepoId: (int) $source['id'],
   newRepoName: $forkName,
   userId: $owner->id,
  );
 }

 /**
  * @return list<array>
  */
 public function listForks(string $repoName): array
 {
  $repo = $this->getRepo($repoName);
  if ($repo === null) {
   return [];
  }

  return $this->dataStore->listForks((int) $repo['id']);
 }

 // -------------------------------------------------------------------------
 // Collaborators
 // -------------------------------------------------------------------------

 public function addCollaborator(string $repoName, string $username, AccessLevel $level): void
 {
  $repo = $this->getRepo($repoName);
  if ($repo === null) {
   throw new \InvalidArgumentException(sprintf('Repository "%s" not found', $repoName));
  }

  $user = $this->getUser($username);
  if ($user === null || $user->id === null) {
   throw new \InvalidArgumentException(sprintf('User "%s" not found', $username));
  }

  $this->dataStore->addCollaborator((int) $repo['id'], $user->id, $level);
 }

 public function removeCollaborator(string $repoName, string $username): void
 {
  $repo = $this->getRepo($repoName);
  if ($repo === null) {
   return;
  }

  $user = $this->getUser($username);
  if ($user === null || $user->id === null) {
   return;
  }

  $this->dataStore->removeCollaborator((int) $repo['id'], $user->id);
 }

 /**
  * @return list<array{user_id: int, username: string, access_level: AccessLevel}>
  */
 public function listCollaborators(string $repoName): array
 {
  $repo = $this->getRepo($repoName);
  if ($repo === null) {
   return [];
  }

  return $this->dataStore->listCollaborators((int) $repo['id']);
 }

 // -------------------------------------------------------------------------
 // Pull Requests
 // -------------------------------------------------------------------------

 public function createPullRequest(
  string $targetRepoName,
  string $authorUsername,
  string $title,
  string $body,
  string $baseBranch,
  string $headBranch,
  ?string $headRepoName = null,
 ): PullRequestModel {
  $targetRepo = $this->getRepo($targetRepoName);
  if ($targetRepo === null) {
   throw new \InvalidArgumentException(sprintf('Target repository "%s" not found', $targetRepoName));
  }

  $author = $this->getUser($authorUsername);
  if ($author === null || $author->id === null) {
   throw new \InvalidArgumentException(sprintf('Author user "%s" not found', $authorUsername));
  }

  $authorAccess = $this->getAccessLevel($targetRepoName, $authorUsername);
  if (!$authorAccess->canRead()) {
   throw new \RuntimeException('Unauthorized: read access to target repository required');
  }

  $headRepoId = (int) $targetRepo['id'];
  if ($headRepoName !== null && $headRepoName !== '' && $headRepoName !== $targetRepoName) {
   $headRepo = $this->getRepo($headRepoName);
   if ($headRepo === null) {
    throw new \InvalidArgumentException(sprintf('Head repository "%s" not found', $headRepoName));
   }
   $headRepoId = (int) $headRepo['id'];
  }

  return $this->dataStore->createPullRequest(
   repoId: (int) $targetRepo['id'],
   userId: $author->id,
   title: $title,
   description: $body,
   baseBranch: $baseBranch,
   headBranch: $headBranch,
   headRepoId: $headRepoId,
  );
 }

 public function getPullRequest(string $targetRepoName, int $number): ?PullRequestModel
 {
  $targetRepo = $this->getRepo($targetRepoName);
  if ($targetRepo === null) {
   return null;
  }

  return $this->dataStore->findPullRequestByNumber((int) $targetRepo['id'], $number);
 }

 /**
  * @return list<PullRequestModel>
  */
 public function listPullRequests(string $targetRepoName, ?string $state = null): array
 {
  $targetRepo = $this->getRepo($targetRepoName);
  if ($targetRepo === null) {
   return [];
  }

  return $this->dataStore->listPullRequests((int) $targetRepo['id'], $state);
 }

 public function updatePullRequest(
  string $targetRepoName,
  int $number,
  string $username,
  string $title,
  string $body,
 ): PullRequestModel {
  $pr = $this->getPullRequest($targetRepoName, $number);
  if ($pr === null || $pr->id === null) {
   throw new \InvalidArgumentException(sprintf('Pull request #%d not found in "%s"', $number, $targetRepoName));
  }

  $user = $this->getUser($username);
  if ($user === null) {
   throw new \RuntimeException('Unauthorized');
  }

  $isAuthor = $pr->authorUsername === $user->username;
  $hasWrite = $this->getAccessLevel($targetRepoName, $username)->canWrite();
  if (!$isAuthor && !$hasWrite) {
   throw new \RuntimeException('Unauthorized to edit this pull request');
  }

  $this->dataStore->updatePullRequestMeta($pr->id, $title, $body);

  return $this->getPullRequest($targetRepoName, $number) ?? $pr;
 }

 public function mergePullRequest(
  string $targetRepoName,
  int $number,
  string $mergedByUsername,
  ?string $mergeCommit = null,
 ): PullRequestModel {
  $targetRepo = $this->getRepo($targetRepoName);
  if ($targetRepo === null) {
   throw new \InvalidArgumentException(sprintf('Target repository "%s" not found', $targetRepoName));
  }

  $user = $this->getUser($mergedByUsername);
  if ($user === null || $user->id === null) {
   throw new \InvalidArgumentException(sprintf('User "%s" not found', $mergedByUsername));
  }

  $access = $this->getAccessLevel($targetRepoName, $mergedByUsername);
  if (!$access->canWrite()) {
   throw new \RuntimeException('Unauthorized: write access required to merge pull request');
  }

  $pr = $this->dataStore->findPullRequestByNumber((int) $targetRepo['id'], $number);
  if ($pr === null || $pr->id === null) {
   throw new \InvalidArgumentException(sprintf('Pull request #%d not found', $number));
  }

  if (!$pr->isOpen()) {
   throw new \RuntimeException(sprintf('Pull request #%d is not open (current state: %s)', $number, $pr->state));
  }

  $this->dataStore->mergePullRequest($pr->id, $user->id, $mergeCommit);

  return $this->dataStore->findPullRequestById($pr->id) ?? $pr;
 }

 public function closePullRequest(string $targetRepoName, int $number, string $username): PullRequestModel
 {
  $targetRepo = $this->getRepo($targetRepoName);
  if ($targetRepo === null) {
   throw new \InvalidArgumentException(sprintf('Target repository "%s" not found', $targetRepoName));
  }

  $pr = $this->dataStore->findPullRequestByNumber((int) $targetRepo['id'], $number);
  if ($pr === null || $pr->id === null) {
   throw new \InvalidArgumentException(sprintf('Pull request #%d not found', $number));
  }

  if (!$pr->isOpen()) {
   throw new \RuntimeException(sprintf('Pull request #%d is not open', $number));
  }

  $user = $this->getUser($username);
  $isAuthor = $user !== null && $pr->authorUsername === $user->username;
  $hasWrite = $this->getAccessLevel($targetRepoName, $username)->canWrite();

  if (!$isAuthor && !$hasWrite) {
   throw new \RuntimeException('Unauthorized to close pull request');
  }

  $this->dataStore->closePullRequest($pr->id);

  return $this->dataStore->findPullRequestById($pr->id) ?? $pr;
 }

 public function reopenPullRequest(string $targetRepoName, int $number, string $username): PullRequestModel
 {
  $targetRepo = $this->getRepo($targetRepoName);
  if ($targetRepo === null) {
   throw new \InvalidArgumentException(sprintf('Target repository "%s" not found', $targetRepoName));
  }

  $pr = $this->dataStore->findPullRequestByNumber((int) $targetRepo['id'], $number);
  if ($pr === null || $pr->id === null) {
   throw new \InvalidArgumentException(sprintf('Pull request #%d not found', $number));
  }

  if (!$pr->isClosed()) {
   throw new \RuntimeException(sprintf('Pull request #%d is not closed', $number));
  }

  $user = $this->getUser($username);
  $isAuthor = $user !== null && $pr->authorUsername === $user->username;
  $hasWrite = $this->getAccessLevel($targetRepoName, $username)->canWrite();

  if (!$isAuthor && !$hasWrite) {
   throw new \RuntimeException('Unauthorized to reopen pull request');
  }

  $this->dataStore->reopenPullRequest($pr->id);

  return $this->dataStore->findPullRequestById($pr->id) ?? $pr;
 }

 public function addPullRequestComment(
  string $targetRepoName,
  int $number,
  string $authorUsername,
  string $body,
  ?string $commitSha = null,
  ?string $filePath = null,
  ?int $line = null,
 ): PullRequestCommentModel {
  $targetRepo = $this->getRepo($targetRepoName);
  if ($targetRepo === null) {
   throw new \InvalidArgumentException(sprintf('Target repository "%s" not found', $targetRepoName));
  }

  $pr = $this->dataStore->findPullRequestByNumber((int) $targetRepo['id'], $number);
  if ($pr === null || $pr->id === null) {
   throw new \InvalidArgumentException(sprintf('Pull request #%d not found', $number));
  }

  $user = $this->getUser($authorUsername);
  if ($user === null || $user->id === null) {
   throw new \InvalidArgumentException(sprintf('User "%s" not found', $authorUsername));
  }

  $access = $this->getAccessLevel($targetRepoName, $authorUsername);
  if (!$access->canRead()) {
   throw new \RuntimeException('Unauthorized: read access required to comment');
  }

  return $this->dataStore->addComment(
   prId: $pr->id,
   userId: $user->id,
   body: $body,
   commitSha: $commitSha,
   filePath: $filePath,
   line: $line,
  );
 }

 /**
  * @return list<PullRequestCommentModel>
  */
 public function listPullRequestComments(string $targetRepoName, int $number): array
 {
  $targetRepo = $this->getRepo($targetRepoName);
  if ($targetRepo === null) {
   return [];
  }

  $pr = $this->dataStore->findPullRequestByNumber((int) $targetRepo['id'], $number);
  if ($pr === null || $pr->id === null) {
   return [];
  }

  return $this->dataStore->listComments($pr->id);
 }

    // -------------------------------------------------------------------------
    // Webhooks
    // -------------------------------------------------------------------------

 /**
  * @param list<string|int> $events
  */
 public function createWebhook(
  string $repoName,
  string $url,
  string $secret,
  array $events = [],
 ): WebhookModel {
  $repo = $this->getRepo($repoName);
  if ($repo === null) {
   throw new \InvalidArgumentException(sprintf('Repository "%s" not found', $repoName));
  }

  return $this->dataStore->createWebhook(
   repoId: (int) $repo['id'],
   url: $url,
   secret: $secret,
   events: $events,
  );
 }

 /**
  * @return list<WebhookModel>
  */
 public function listWebhooks(string $repoName): array
 {
  $repo = $this->getRepo($repoName);
  if ($repo === null) {
   return [];
  }

  return $this->dataStore->listWebhooks((int) $repo['id']);
 }

 public function deleteWebhook(int $webhookId): bool
 {
  return $this->dataStore->deleteWebhook($webhookId);
 }

 // -------------------------------------------------------------------------
 // Settings
 // -------------------------------------------------------------------------

 public function getSetting(string $key, ?string $default = null): ?string
 {
  return $this->dataStore->getSetting($key, $default);
 }

 public function setSetting(string $key, string $value): void
 {
  $this->dataStore->setSetting($key, $value);
 }

 public function setPrivate(string $repoName, bool $private): void
 {
  $repo = $this->getRepo($repoName);
  if ($repo === null) {
   throw new \RuntimeException(sprintf('Repository "%s" not found', $repoName));
  }
  $this->dataStore->setRepoPrivate((int) $repo['id'], $private);
 }

 public function setHidden(string $repoName, bool $hidden): void
 {
  $repo = $this->getRepo($repoName);
  if ($repo === null) {
   throw new \RuntimeException(sprintf('Repository "%s" not found', $repoName));
  }
  $this->dataStore->setRepoHidden((int) $repo['id'], $hidden);
 }

 public function setDescription(string $repoName, string $description): void
 {
  $repo = $this->getRepo($repoName);
  if ($repo === null) {
   throw new \RuntimeException(sprintf('Repository "%s" not found', $repoName));
  }
  $this->dataStore->setRepoDescription((int) $repo['id'], $description);

  $repoPath = $this->getRepoPath($repoName);
  if (is_dir($repoPath)) {
   @file_put_contents(rtrim($repoPath, '/') . '/description', trim($description) . "\n");
  }
 }

 public function setProjectName(string $repoName, string $projectName): void
 {
  $repo = $this->getRepo($repoName);
  if ($repo === null) {
   throw new \RuntimeException(sprintf('Repository "%s" not found', $repoName));
  }
  $this->dataStore->setRepoProjectName((int) $repo['id'], $projectName);
 }

 public function setMirror(string $repoName, bool $mirror): void
 {
  $repo = $this->getRepo($repoName);
  if ($repo === null) {
   throw new \RuntimeException(sprintf('Repository "%s" not found', $repoName));
  }
  $this->dataStore->setRepoMirror((int) $repo['id'], $mirror);
 }

 public function renameRepo(string $oldName, string $newName): void
 {
  $repo = $this->getRepo($oldName);
  if ($repo === null) {
   throw new \RuntimeException(sprintf('Repository "%s" not found', $oldName));
  }
  $existing = $this->getRepo($newName);
  if ($existing !== null) {
   throw new \RuntimeException(sprintf('Repository "%s" already exists', $newName));
  }

  $this->dataStore->renameRepo((int) $repo['id'], $newName);

  $oldPath = $this->getRepoPath($oldName);
  $newPath = $this->getRepoPath($newName);
  if (is_dir($oldPath)) {
   $newParent = dirname($newPath);
   if (!is_dir($newParent)) {
    @mkdir($newParent, 0755, true);
   }
   @rename($oldPath, $newPath);
  }
 }

 public function setUserAdmin(string $username, bool $isAdmin): void
 {
  $user = $this->getUser($username);
  if ($user === null || $user->id === null) {
   throw new \RuntimeException(sprintf('User "%s" not found', $username));
  }
  $this->dataStore->setUserAdmin($user->id, $isAdmin);
 }

 public function setUsername(string $oldUsername, string $newUsername): void
 {
  $user = $this->getUser($oldUsername);
  if ($user === null || $user->id === null) {
   throw new \RuntimeException(sprintf('User "%s" not found', $oldUsername));
  }
  $existing = $this->getUser($newUsername);
  if ($existing !== null) {
   throw new \RuntimeException(sprintf('Username "%s" already taken', $newUsername));
  }
  $this->dataStore->setUsername($user->id, $newUsername);
 }

 /**
  * @return array<string, mixed>
  */
 public function createAccessToken(string $username, string $name, ?\DateTimeInterface $expiresAt = null): array
 {
  $user = $this->getUser($username);
  if ($user === null || $user->id === null) {
   throw new \RuntimeException(sprintf('User "%s" not found', $username));
  }
  return $this->dataStore->createAccessToken($user->id, $name, null, $expiresAt);
 }

 /**
  * @return list<array<string, mixed>>
  */
 public function listAccessTokens(string $username): array
 {
  $user = $this->getUser($username);
  if ($user === null || $user->id === null) {
   throw new \RuntimeException(sprintf('User "%s" not found', $username));
  }
  return $this->dataStore->listAccessTokens($user->id);
 }

 public function deleteAccessToken(string $username, int $id): bool
 {
  $user = $this->getUser($username);
  if ($user === null || $user->id === null) {
   throw new \RuntimeException(sprintf('User "%s" not found', $username));
  }
  return $this->dataStore->deleteAccessToken($user->id, $id);
 }

 public function deletePullRequest(string $targetRepoName, int $number): bool
 {
  $pr = $this->getPullRequest($targetRepoName, $number);
  if ($pr === null || $pr->id === null) {
   throw new \RuntimeException(sprintf('Pull request #%d not found in repository "%s"', $number, $targetRepoName));
  }
  return $this->dataStore->deletePullRequest($pr->id);
 }

    // -------------------------------------------------------------------------
    // Server Hooks
    // Matches pkg/backend/hooks.go in Soft Serve
    // -------------------------------------------------------------------------

 /**
  * PreReceive hook handler.
  *
  * @param string $repoName
  * @param list<array<string, string>> $opts
  */
 public function preReceive(string $repoName, array $opts): void
 {
  // Matches PreReceive in pkg/backend/hooks.go
 }

 /**
  * Update hook handler.
  * Matches Update in pkg/backend/hooks.go.
  */
 public function updateHook(string $repoName, string $refName, string $oldSha, string $newSha): void
 {
  $user = null;
  $pubkey = getenv('SOFT_SERVE_PUBLIC_KEY');
  $username = getenv('SOFT_SERVE_USERNAME');

  if ($pubkey !== false && $pubkey !== '') {
   $user = $this->getUserByPublicKey($pubkey);
  } elseif ($username !== false && $username !== '') {
   $user = $this->getUserByUsername($username);
  }

  $repo = $this->dataStore->findRepoByName($repoName);
  if ($repo === null) {
   return;
  }

  if ($this->webhookDispatcher !== null) {
   if (WebhookDispatcher::isZeroHash($oldSha) || WebhookDispatcher::isZeroHash($newSha)) {
    $this->webhookDispatcher->dispatchBranchTagEvent(
     repoName: $repoName,
     refName: $refName,
     oldSha: $oldSha,
     newSha: $newSha,
     user: $user,
    );
   }

   $this->webhookDispatcher->dispatchPushEvent(
    repoName: $repoName,
    changes: [
     [
      'ref' => $refName,
      'old' => $oldSha,
      'new' => $newSha,
     ],
    ],
    user: $user,
   );
  }
 }

 /**
  * Populates $repoPath/info/last-modified with the latest commit timestamp.
  * Matches populateLastModified in pkg/backend/hooks.go.
  */
 public function populateLastModified(string $repoPath): ?string
 {
  $infoDir = $repoPath . '/info';
  if (!is_dir($infoDir)) {
   @mkdir($infoDir, 0755, true);
  }

  $process = new Process(['git', 'log', '-1', '--format=%cI'], $repoPath);
  $process->run();
  if (!$process->isSuccessful() || trim($process->getOutput()) === '') {
   $process = new Process(['git', 'log', '-1', '--format=%cd'], $repoPath);
   $process->run();
  }

  if ($process->isSuccessful()) {
   $timeStr = trim($process->getOutput());
   if ($timeStr !== '') {
    file_put_contents($infoDir . '/last-modified', $timeStr);
    return $timeStr;
   }
  }

  return null;
 }
}
