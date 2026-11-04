<?php

declare(strict_types=1);

namespace App\Service\Lfs;

use InvalidArgumentException;
use RuntimeException;
use PDO;

/**
 * Service managing Git LFS content-addressable storage on filesystem,
 * LFS Batch API negotiation, and Git LFS Locks API.
 *
 * Implements compatibility with Soft Serve's `pkg/lfs/*` and `pkg/web/git_lfs*.go`.
 */
class LfsStorageService
{
 private string $lfsRoot;
 private PDO $pdo;

 public function __construct(?string $lfsRoot = null, ?PDO $pdo = null)
 {
  $this->lfsRoot = $lfsRoot ?? (sys_get_temp_dir() . '/codes_sh_lfs');
  if (!is_dir($this->lfsRoot)) {
   mkdir($this->lfsRoot, 0755, true);
  }

  if ($pdo !== null) {
   $this->pdo = $pdo;
  } else {
   $this->pdo = new PDO('sqlite::memory:');
   $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
   $this->initDefaultSchema();
  }
 }

 private function initDefaultSchema(): void
 {
  $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS lfs_objects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                oid TEXT NOT NULL,
                size INTEGER NOT NULL,
                repo_id INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (oid, repo_id)
            );

            CREATE TABLE IF NOT EXISTS lfs_locks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                repo_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                path TEXT NOT NULL,
                refname TEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (repo_id, path)
            );

            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                is_admin INTEGER NOT NULL DEFAULT 0
            );
        ');
 }

 /**
  * Returns the root storage directory for LFS objects.
  */
 public function getLfsRoot(): string
 {
  return $this->lfsRoot;
 }

 /**
  * Resolves the filesystem path for an OID: <lfsRoot>/objects/<oid[0:2]>/<oid[2:4]>/<oid>.
  *
  * @throws InvalidArgumentException When the OID is invalid.
  */
 public function getObjectPath(string $oid): string
 {
  $cleanOid = strtolower(trim($oid));
  if (strlen($cleanOid) !== 64 || !preg_match('/^[a-f0-9]{64}$/', $cleanOid)) {
   throw new InvalidArgumentException(
    sprintf('Invalid OID: "%s". Must be 64 lowercase hex characters.', $oid)
   );
  }

  return sprintf(
   '%s/objects/%s/%s/%s',
   rtrim($this->lfsRoot, '/'),
   substr($cleanOid, 0, 2),
   substr($cleanOid, 2, 2),
   $cleanOid
  );
 }

 /**
  * Stores an LFS object, strictly verifying its SHA-256 hash and size before
  * moving it atomically into its content-addressable storage path.
  *
  * @throws InvalidArgumentException When size or hash verification fails.
  * @throws RuntimeException On filesystem write or rename failure.
  */
 public function putObject(string $oid, int $expectedSize, string $data, int $repoId = 1): void
 {
  $cleanOid = strtolower(trim($oid));
  $actualSize = strlen($data);

  if ($actualSize !== $expectedSize) {
   throw new InvalidArgumentException(
    sprintf('LFS size mismatch for OID %s: expected %d bytes, got %d bytes', $cleanOid, $expectedSize, $actualSize)
   );
  }

  $actualSha = hash('sha256', $data);
  if ($actualSha !== $cleanOid) {
   throw new InvalidArgumentException(
    sprintf('LFS SHA-256 hash mismatch for OID %s: calculated %s', $cleanOid, $actualSha)
   );
  }

  $targetPath = $this->getObjectPath($cleanOid);
  $targetDir = dirname($targetPath);

  if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
   throw new RuntimeException(sprintf('Failed to create LFS storage directory "%s"', $targetDir));
  }

  $tempPath = sprintf('%s/%s.tmp.%s', $targetDir, $cleanOid, bin2hex(random_bytes(6)));
  if (file_put_contents($tempPath, $data) === false) {
   throw new RuntimeException(sprintf('Failed to write temporary LFS file "%s"', $tempPath));
  }

  if (!rename($tempPath, $targetPath)) {
   @unlink($tempPath);
   throw new RuntimeException(sprintf('Failed to atomically move temporary file to "%s"', $targetPath));
  }

  // Record in database
  try {
   $stmt = $this->pdo->prepare('
                INSERT OR IGNORE INTO lfs_objects (oid, size, repo_id, updated_at)
                VALUES (:oid, :size, :repo_id, CURRENT_TIMESTAMP)
            ');
   $stmt->execute([
    'oid' => $cleanOid,
    'size' => $expectedSize,
    'repo_id' => $repoId,
   ]);
  } catch (\Throwable) {
   // Best effort DB write
  }
 }

 /**
  * Retrieves the binary content of an LFS object, or null if it does not exist.
  */
 public function getObject(string $oid): ?string
 {
  try {
   $path = $this->getObjectPath($oid);
  } catch (InvalidArgumentException) {
   return null;
  }

  if (!is_file($path)) {
   return null;
  }

  $content = file_get_contents($path);
  return $content !== false ? $content : null;
 }

 /**
  * Checks if an LFS object exists and has the expected size.
  */
 public function hasObject(string $oid, int $expectedSize): bool
 {
  try {
   $path = $this->getObjectPath($oid);
  } catch (InvalidArgumentException) {
   return false;
  }

  if (!is_file($path)) {
   return false;
  }

  $size = filesize($path);
  return $size !== false && $size === $expectedSize;
 }

 /**
  * Handles a Git LFS Batch API request (POST /info/lfs/objects/batch).
  *
  * Constructs the standard Git LFS Batch JSON response with `transfer: "basic"`
  * and actions or object-level errors.
  *
  * @param string $repo Repository name
  * @param string $operation 'download' or 'upload'
  * @param array<array{oid?: mixed, size?: mixed}> $objects List of requested objects
  * @param string $baseUrl Base HTTP URL of the server (e.g. 'https://git.example.com')
  * @param string|null $authHeader Optional Authorization header to propagate to action URLs
  * @return array Standard Git LFS Batch response structure
  */
 public function handleBatchRequest(
  string $repo,
  string $operation,
  array $objects,
  string $baseUrl,
  ?string $authHeader = null
 ): array {
  $repoClean = trim($repo, '/');
  if (!str_ends_with($repoClean, '.git')) {
   $repoClean .= '.git';
  }

  if (str_contains($baseUrl, '/info/lfs/objects/basic')) {
   $baseHref = rtrim($baseUrl, '/');
  } else {
   $baseHref = sprintf('%s/%s/info/lfs/objects/basic', rtrim($baseUrl, '/'), $repoClean);
  }

  $response = [
   'transfer' => 'basic',
   'objects' => [],
   'hash_algo' => 'sha256',
  ];

  foreach ($objects as $obj) {
   $rawOid = (string) ($obj['oid'] ?? '');
   $rawSize = $obj['size'] ?? -1;

   $isValidOid = strlen($rawOid) === 64 && preg_match('/^[a-f0-9]{64}$/i', $rawOid);
   $isValidSize = is_int($rawSize) && $rawSize >= 0;

   if (!$isValidOid || !$isValidSize) {
    $response['objects'][] = [
     'oid' => $rawOid,
     'size' => is_int($rawSize) ? $rawSize : 0,
     'error' => [
      'code' => 422,
      'message' => 'Validation error: invalid OID or size',
     ],
    ];
    continue;
   }

   $oid = strtolower($rawOid);
   $size = $rawSize;

   if ($operation === 'download') {
    if (!file_exists($this->getObjectPath($oid))) {
     $response['objects'][] = [
      'oid' => $oid,
      'size' => $size,
      'error' => [
       'code' => 404,
       'message' => 'Object not found',
      ],
     ];
    } elseif (!$this->hasObject($oid, $size)) {
     // Object exists on disk, but size mismatches
     $response['objects'][] = [
      'oid' => $oid,
      'size' => $size,
      'error' => [
       'code' => 422,
       'message' => 'size mismatch',
      ],
     ];
    } else {
     $downloadAction = [
      'href' => sprintf('%s/%s', $baseHref, $oid),
     ];
     if ($authHeader !== null && $authHeader !== '') {
      $downloadAction['header'] = ['Authorization' => $authHeader];
     }

     $response['objects'][] = [
      'oid' => $oid,
      'size' => $size,
      'actions' => [
       'download' => $downloadAction,
      ],
     ];
    }
   } elseif ($operation === 'upload') {
    $uploadAction = [
     'href' => sprintf('%s/%s', $baseHref, $oid),
     'header' => [
      'Content-Type' => 'application/octet-stream',
     ],
    ];
    $verifyAction = [
     'href' => sprintf('%s/verify', $baseHref),
    ];

    if ($authHeader !== null && $authHeader !== '') {
     $uploadAction['header']['Authorization'] = $authHeader;
     $verifyAction['header'] = ['Authorization' => $authHeader];
    }

    $response['objects'][] = [
     'oid' => $oid,
     'size' => $size,
     'actions' => [
      'upload' => $uploadAction,
      'verify' => $verifyAction,
     ],
    ];
   } else {
    throw new InvalidArgumentException(sprintf('Unsupported LFS operation: "%s"', $operation));
   }
  }

  return $response;
 }

 /**
  * Handles LFS Basic download request (GET /info/lfs/objects/basic/<oid>).
  *
  * @return array{status: int, headers: array<string, string>, body: string}
  */
 public function handleDownload(string $oid): array
 {
  $content = $this->getObject($oid);
  if ($content === null) {
   return [
    'status' => 404,
    'headers' => ['Content-Type' => 'application/vnd.git-lfs+json'],
    'body' => json_encode(['message' => 'object not found'], JSON_THROW_ON_ERROR),
   ];
  }

  return [
   'status' => 200,
   'headers' => [
    'Content-Type' => 'application/octet-stream',
    'Content-Length' => (string) strlen($content),
   ],
   'body' => $content,
  ];
 }

 /**
  * Handles LFS Basic upload request (PUT /info/lfs/objects/basic/<oid>).
  *
  * @return array{status: int, headers: array<string, string>, body: string}
  */
 public function handleUpload(string $oid, string $data, ?string $contentType = null): array
 {
  if ($contentType !== null && !str_starts_with($contentType, 'application/octet-stream')) {
   return [
    'status' => 415,
    'headers' => ['Content-Type' => 'application/vnd.git-lfs+json'],
    'body' => json_encode(['message' => 'invalid content type'], JSON_THROW_ON_ERROR),
   ];
  }

  try {
   $this->putObject($oid, strlen($data), $data);
   return [
    'status' => 200,
    'headers' => [],
    'body' => '',
   ];
  } catch (\InvalidArgumentException $e) {
   return [
    'status' => 422,
    'headers' => ['Content-Type' => 'application/vnd.git-lfs+json'],
    'body' => json_encode(['message' => $e->getMessage()], JSON_THROW_ON_ERROR),
   ];
  }
 }

 /**
  * Handles LFS Basic verify request (POST /info/lfs/objects/basic/verify).
  *
  * @return array{status: int, headers: array<string, string>, body: string}
  */
 public function handleVerify(string $oid, int $size): array
 {
  try {
   $path = $this->getObjectPath($oid);
  } catch (\Throwable $e) {
   return [
    'status' => 422,
    'headers' => ['Content-Type' => 'application/vnd.git-lfs+json'],
    'body' => json_encode(['message' => $e->getMessage()], JSON_THROW_ON_ERROR),
   ];
  }

  if (!file_exists($path)) {
   return [
    'status' => 404,
    'headers' => ['Content-Type' => 'application/vnd.git-lfs+json'],
    'body' => json_encode(['message' => 'object not found'], JSON_THROW_ON_ERROR),
   ];
  }

  $actualSize = filesize($path);
  if ($actualSize !== $size) {
   return [
    'status' => 422,
    'headers' => ['Content-Type' => 'application/vnd.git-lfs+json'],
    'body' => json_encode(['message' => 'size mismatch'], JSON_THROW_ON_ERROR),
   ];
  }

  return [
   'status' => 200,
   'headers' => ['Content-Type' => 'application/vnd.git-lfs+json'],
   'body' => json_encode(['message' => 'ok'], JSON_THROW_ON_ERROR),
  ];
 }

    // =========================================================================
    // LFS Locks API
    // =========================================================================

 /**
  * Creates an LFS file lock for a repository and user.
  *
  * @return array{status: int, lock?: array, message?: string}
  */
 public function createLock(
  int $repoId,
  int $userId,
  string $username,
  string $path,
  ?string $refname = null
 ): array {
  $cleanPath = trim($path, " \t\n\r\0\x0B/");

  // Check if lock already exists
  $stmt = $this->pdo->prepare('
            SELECT l.*, u.username AS owner_name
            FROM lfs_locks l
            LEFT JOIN users u ON u.id = l.user_id
            WHERE l.repo_id = :repo_id AND l.path = :path
            LIMIT 1
        ');
  $stmt->execute(['repo_id' => $repoId, 'path' => $cleanPath]);
  $existing = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($existing !== false) {
   return [
    'status' => 409,
    'message' => 'lock already exists',
    'lock' => [
     'id' => (string) $existing['id'],
     'path' => $existing['path'],
     'locked_at' => $existing['created_at'],
     'owner' => [
      'name' => $existing['owner_name'] ?? $username,
     ],
    ],
   ];
  }

  $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
  $ins = $this->pdo->prepare('
            INSERT INTO lfs_locks (repo_id, user_id, path, refname, created_at, updated_at)
            VALUES (:repo_id, :user_id, :path, :refname, :created_at, :updated_at)
        ');
  $ins->execute([
   'repo_id' => $repoId,
   'user_id' => $userId,
   'path' => $cleanPath,
   'refname' => $refname,
   'created_at' => $now,
   'updated_at' => $now,
  ]);

  $lockId = (string) $this->pdo->lastInsertId();

  return [
   'status' => 201,
   'lock' => [
    'id' => $lockId,
    'path' => $cleanPath,
    'locked_at' => $now,
    'owner' => [
     'name' => $username,
    ],
   ],
  ];
 }

 /**
  * Lists LFS locks matching criteria.
  *
  * @param array{id?: int|string, path?: string, cursor?: int|string, limit?: int|string} $filters
  * @return array{locks: list<array>, next_cursor?: string}
  */
 public function listLocks(int $repoId, array $filters = []): array
 {
  $limit = isset($filters['limit']) ? (int) $filters['limit'] : 10;
  if ($limit > 100) {
   $limit = 100;
  } elseif ($limit <= 0) {
   $limit = 10;
  }

  $cursor = isset($filters['cursor']) ? (int) $filters['cursor'] : 1;
  if ($cursor <= 0) {
   $cursor = 1;
  }

  $offset = ($cursor - 1) * $limit;

  if (isset($filters['id']) && (int) $filters['id'] > 0) {
   $stmt = $this->pdo->prepare('
                SELECT l.*, u.username AS owner_name
                FROM lfs_locks l
                LEFT JOIN users u ON u.id = l.user_id
                WHERE l.repo_id = :repo_id AND l.id = :id
                LIMIT 1
            ');
   $stmt->execute(['repo_id' => $repoId, 'id' => (int) $filters['id']]);
   $row = $stmt->fetch(PDO::FETCH_ASSOC);

   if ($row === false) {
    return ['locks' => []];
   }

   return [
    'locks' => [
     [
      'id' => (string) $row['id'],
      'path' => $row['path'],
      'locked_at' => $row['created_at'],
      'owner' => ['name' => $row['owner_name'] ?? 'user'],
     ],
    ],
   ];
  }

  if (isset($filters['path']) && trim($filters['path']) !== '') {
   $stmt = $this->pdo->prepare('
                SELECT l.*, u.username AS owner_name
                FROM lfs_locks l
                LEFT JOIN users u ON u.id = l.user_id
                WHERE l.repo_id = :repo_id AND l.path = :path
                LIMIT 1
            ');
   $stmt->execute(['repo_id' => $repoId, 'path' => trim($filters['path'])]);
   $row = $stmt->fetch(PDO::FETCH_ASSOC);

   if ($row === false) {
    return ['locks' => []];
   }

   return [
    'locks' => [
     [
      'id' => (string) $row['id'],
      'path' => $row['path'],
      'locked_at' => $row['created_at'],
      'owner' => ['name' => $row['owner_name'] ?? 'user'],
     ],
    ],
   ];
  }

  $stmt = $this->pdo->prepare('
            SELECT l.*, u.username AS owner_name
            FROM lfs_locks l
            LEFT JOIN users u ON u.id = l.user_id
            WHERE l.repo_id = :repo_id
            ORDER BY l.id ASC
            LIMIT :limit OFFSET :offset
        ');
  $stmt->bindValue(':repo_id', $repoId, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $locks = [];
  foreach ($rows as $row) {
   $locks[] = [
    'id' => (string) $row['id'],
    'path' => $row['path'],
    'locked_at' => $row['created_at'],
    'owner' => ['name' => $row['owner_name'] ?? 'user'],
   ];
  }

  $result = ['locks' => $locks];
  if (count($locks) === $limit) {
   $result['next_cursor'] = (string) ($cursor + 1);
  }

  return $result;
 }

 /**
  * Verifies locks for a given user, returning 'ours' and 'theirs'.
  *
  * @return array{ours: list<array>, theirs: list<array>, next_cursor?: string}
  */
 public function verifyLocks(int $repoId, int $userId, int $cursor = 1, int $limit = 100): array
 {
  if ($limit > 100) {
   $limit = 100;
  } elseif ($limit <= 0) {
   $limit = 10;
  }

  if ($cursor <= 0) {
   $cursor = 1;
  }

  $offset = ($cursor - 1) * $limit;

  $stmt = $this->pdo->prepare('
            SELECT l.*, u.username AS owner_name
            FROM lfs_locks l
            LEFT JOIN users u ON u.id = l.user_id
            WHERE l.repo_id = :repo_id
            ORDER BY l.id ASC
            LIMIT :limit OFFSET :offset
        ');
  $stmt->bindValue(':repo_id', $repoId, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $ours = [];
  $theirs = [];

  foreach ($rows as $row) {
   $lockData = [
    'id' => (string) $row['id'],
    'path' => $row['path'],
    'locked_at' => $row['created_at'],
    'owner' => ['name' => $row['owner_name'] ?? 'user'],
   ];

   if ((int) $row['user_id'] === $userId) {
    $ours[] = $lockData;
   } else {
    $theirs[] = $lockData;
   }
  }

  $resp = ['ours' => $ours, 'theirs' => $theirs];
  if (count($rows) === $limit) {
   $resp['next_cursor'] = (string) ($cursor + 1);
  }

  return $resp;
 }

 /**
  * Unlocks/deletes an LFS lock.
  *
  * @return array{status: int, lock?: array, message?: string}
  */
 public function deleteLock(int $repoId, int $userId, bool $isAdmin, int $lockId, bool $force = false): array
 {
  $stmt = $this->pdo->prepare('
            SELECT l.*, u.username AS owner_name
            FROM lfs_locks l
            LEFT JOIN users u ON u.id = l.user_id
            WHERE l.repo_id = :repo_id AND l.id = :id
            LIMIT 1
        ');
  $stmt->execute(['repo_id' => $repoId, 'id' => $lockId]);
  $lock = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($lock === false) {
   return [
    'status' => 404,
    'message' => 'lock not found',
   ];
  }

  $lockArray = [
   'id' => (string) $lock['id'],
   'path' => $lock['path'],
   'locked_at' => $lock['created_at'],
   'owner' => ['name' => $lock['owner_name'] ?? 'user'],
  ];

  if ($force) {
   if (!$isAdmin) {
    return [
     'status' => 403,
     'message' => 'admin access required for force delete',
    ];
   }
  } else {
   if ((int) $lock['user_id'] !== $userId) {
    return [
     'status' => 403,
     'message' => 'lock belongs to another user',
    ];
   }
  }

  $del = $this->pdo->prepare('DELETE FROM lfs_locks WHERE repo_id = :repo_id AND id = :id');
  $del->execute(['repo_id' => $repoId, 'id' => $lockId]);

  return [
   'status' => 200,
   'lock' => $lockArray,
  ];
 }
}
