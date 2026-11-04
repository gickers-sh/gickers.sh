<?php

declare(strict_types=1);

namespace App\Service\Store;

use App\Access\Domain\AccessLevel;
use App\Model\PullRequestCommentModel;
use App\Model\PullRequestModel;
use App\Model\UserModel;
use App\Model\WebhookModel;
use App\Service\Database\DatabaseManager;

/**
 * DataStore provides domain-level CRUD and query operations backed by DatabaseManager.
 */
class DataStore
{
    public function __construct(
        private readonly DatabaseManager $databaseManager,
    ) {
    }

    public function getDatabaseManager(): DatabaseManager
    {
        return $this->databaseManager;
    }

    private function getPdo(): \PDO
    {
        return $this->databaseManager->getConnection();
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    public function getSetting(string $key, ?string $default = null): ?string
    {
        $stmt = $this->getPdo()->prepare('SELECT value FROM settings WHERE key = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch();

        return false !== $row ? (string) $row['value'] : $default;
    }

    public function setSetting(string $key, string $value): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->getPdo()->prepare(
            'INSERT INTO settings (key, value, created_at, updated_at)
             VALUES (:key, :value, :now, :now)
             ON CONFLICT(key) DO UPDATE SET value = :value, updated_at = :now'
        );
        $stmt->execute(['key' => $key, 'value' => $value, 'now' => $now]);
    }

    // -------------------------------------------------------------------------
    // Users & Public Keys
    // -------------------------------------------------------------------------

    public function createUser(string $username, bool $isAdmin = false, ?string $password = null): UserModel
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $normalizedUsername = strtolower(trim($username));

        $stmt = $this->getPdo()->prepare(
            'INSERT INTO users (username, admin, password, created_at, updated_at)
             VALUES (:username, :admin, :password, :created_at, :updated_at)'
        );
        $stmt->execute([
            'username' => $normalizedUsername,
            'admin' => $isAdmin ? 1 : 0,
            'password' => $password,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->getPdo()->lastInsertId();

        return new UserModel(
            id: $id,
            username: $normalizedUsername,
            isAdmin: $isAdmin,
            publicKeys: [],
            createdAt: new \DateTimeImmutable($now),
            updatedAt: new \DateTimeImmutable($now),
        );
    }

    public function findUserById(int $id): ?UserModel
    {
        $stmt = $this->getPdo()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (false === $row) {
            return null;
        }

        return $this->hydrateUser($row);
    }

    public function findUserByUsername(string $username): ?UserModel
    {
        $stmt = $this->getPdo()->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => strtolower(trim($username))]);
        $row = $stmt->fetch();

        if (false === $row) {
            return null;
        }

        return $this->hydrateUser($row);
    }

    public function findUserByPublicKey(string $publicKey): ?UserModel
    {
        $cleanKey = trim($publicKey);
        $stmt = $this->getPdo()->prepare(
            'SELECT u.* FROM users u
             INNER JOIN public_keys pk ON pk.user_id = u.id
             WHERE pk.public_key = :key LIMIT 1'
        );
        $stmt->execute(['key' => $cleanKey]);
        $row = $stmt->fetch();

        if (false === $row) {
            return null;
        }

        return $this->hydrateUser($row);
    }

    /**
     * @return list<UserModel>
     */
    public function listUsers(): array
    {
        $stmt = $this->getPdo()->query('SELECT * FROM users ORDER BY username ASC');
        $rows = $stmt->fetchAll();

        $users = [];
        foreach ($rows as $row) {
            $users[] = $this->hydrateUser($row);
        }

        return $users;
    }

    public function deleteUser(int $id): bool
    {
        $stmt = $this->getPdo()->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function addPublicKey(int $userId, string $publicKey): void
    {
        $cleanKey = trim($publicKey);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->getPdo()->prepare(
            'INSERT INTO public_keys (user_id, public_key, created_at, updated_at)
             VALUES (:user_id, :public_key, :now, :now)
             ON CONFLICT(public_key) DO UPDATE SET updated_at = :now'
        );
        $stmt->execute([
            'user_id' => $userId,
            'public_key' => $cleanKey,
            'now' => $now,
        ]);
    }

    public function removePublicKey(int $userId, string $publicKey): void
    {
        $stmt = $this->getPdo()->prepare(
            'DELETE FROM public_keys WHERE user_id = :user_id AND public_key = :key'
        );
        $stmt->execute(['user_id' => $userId, 'key' => trim($publicKey)]);
    }

    /**
     * @return list<string>
     */
    public function listPublicKeys(int $userId): array
    {
        $stmt = $this->getPdo()->prepare('SELECT public_key FROM public_keys WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function hydrateUser(array $row): UserModel
    {
        $userId = (int) $row['id'];
        $keys = $this->listPublicKeys($userId);

        return new UserModel(
            id: $userId,
            username: (string) $row['username'],
            isAdmin: (bool) $row['admin'],
            publicKeys: $keys,
            createdAt: isset($row['created_at']) ? new \DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new \DateTimeImmutable((string) $row['updated_at']) : null,
        );
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
        int $userId,
        ?int $parentRepoId = null,
    ): int {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $isFork = null !== $parentRepoId ? 1 : 0;

        $stmt = $this->getPdo()->prepare(
            'INSERT INTO repos (name, project_name, description, private, mirror, hidden, user_id, fork_id, parent_repo_id, is_fork, created_at, updated_at)
             VALUES (:name, :project_name, :description, :private, :mirror, :hidden, :user_id, :fork_id, :parent_repo_id, :is_fork, :created_at, :updated_at)'
        );
        $stmt->execute([
            'name' => strtolower(trim($name)),
            'project_name' => $projectName,
            'description' => $description,
            'private' => $private ? 1 : 0,
            'mirror' => $mirror ? 1 : 0,
            'hidden' => $hidden ? 1 : 0,
            'user_id' => $userId,
            'fork_id' => $parentRepoId,
            'parent_repo_id' => $parentRepoId,
            'is_fork' => $isFork,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->getPdo()->lastInsertId();
    }

    public function findRepoByName(string $name): ?array
    {
        $stmt = $this->getPdo()->prepare('SELECT * FROM repos WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => strtolower(trim($name))]);
        $row = $stmt->fetch();

        return false !== $row ? $row : null;
    }

    public function findRepoById(int $id): ?array
    {
        $stmt = $this->getPdo()->prepare('SELECT * FROM repos WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return false !== $row ? $row : null;
    }

    /**
     * @return list<array>
     */
    public function listRepos(): array
    {
        $stmt = $this->getPdo()->query('SELECT * FROM repos ORDER BY name ASC');

        return $stmt->fetchAll();
    }

    public function deleteRepo(int $id): bool
    {
        $stmt = $this->getPdo()->prepare('DELETE FROM repos WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function forkRepo(int $sourceRepoId, string $newRepoName, int $userId): int
    {
        $source = $this->findRepoById($sourceRepoId);
        if (null === $source) {
            throw new \InvalidArgumentException(sprintf('Source repository ID %d not found', $sourceRepoId));
        }

        return $this->createRepo(
            name: $newRepoName,
            projectName: (string) $source['project_name'],
            description: (string) $source['description'],
            private: (bool) $source['private'],
            mirror: false,
            hidden: (bool) $source['hidden'],
            userId: $userId,
            parentRepoId: $sourceRepoId,
        );
    }

    /**
     * @return list<array>
     */
    public function listForks(int $repoId): array
    {
        $stmt = $this->getPdo()->prepare(
            'SELECT * FROM repos WHERE fork_id = :repoId OR parent_repo_id = :repoId ORDER BY name ASC'
        );
        $stmt->execute(['repoId' => $repoId]);

        return $stmt->fetchAll();
    }

    // -------------------------------------------------------------------------
    // Collaborators
    // -------------------------------------------------------------------------

    public function addCollaborator(int $repoId, int $userId, AccessLevel $accessLevel): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->getPdo()->prepare(
            'INSERT INTO collabs (user_id, repo_id, access_level, created_at, updated_at)
             VALUES (:user_id, :repo_id, :access_level, :now, :now)
             ON CONFLICT(user_id, repo_id) DO UPDATE SET access_level = :access_level, updated_at = :now'
        );
        $stmt->execute([
            'user_id' => $userId,
            'repo_id' => $repoId,
            'access_level' => $accessLevel->value,
            'now' => $now,
        ]);
    }

    public function removeCollaborator(int $repoId, int $userId): void
    {
        $stmt = $this->getPdo()->prepare('DELETE FROM collabs WHERE user_id = :user_id AND repo_id = :repo_id');
        $stmt->execute(['user_id' => $userId, 'repo_id' => $repoId]);
    }

    public function getCollaborator(int $repoId, int $userId): ?AccessLevel
    {
        $stmt = $this->getPdo()->prepare('SELECT access_level FROM collabs WHERE repo_id = :repo_id AND user_id = :user_id LIMIT 1');
        $stmt->execute(['repo_id' => $repoId, 'user_id' => $userId]);
        $row = $stmt->fetch();

        return false !== $row ? AccessLevel::from((int) $row['access_level']) : null;
    }

    /**
     * @return list<array{user_id: int, username: string, access_level: AccessLevel}>
     */
    public function listCollaborators(int $repoId): array
    {
        $stmt = $this->getPdo()->prepare(
            'SELECT c.user_id, u.username, c.access_level
             FROM collabs c
             INNER JOIN users u ON u.id = c.user_id
             WHERE c.repo_id = :repo_id
             ORDER BY u.username ASC'
        );
        $stmt->execute(['repo_id' => $repoId]);
        $rows = $stmt->fetchAll();

        $collabs = [];
        foreach ($rows as $row) {
            $collabs[] = [
                'user_id' => (int) $row['user_id'],
                'username' => (string) $row['username'],
                'access_level' => AccessLevel::from((int) $row['access_level']),
            ];
        }

        return $collabs;
    }

    // -------------------------------------------------------------------------
    // Pull Requests
    // -------------------------------------------------------------------------

    public function getNextPullRequestNumber(int $repoId): int
    {
        $stmt = $this->getPdo()->prepare('SELECT COALESCE(MAX(number), 0) + 1 AS next_num FROM pull_requests WHERE repo_id = :repo_id');
        $stmt->execute(['repo_id' => $repoId]);
        $row = $stmt->fetch();

        return (int) ($row['next_num'] ?? 1);
    }

    public function createPullRequest(
        int $repoId,
        int $userId,
        string $title,
        string $description,
        string $baseBranch,
        string $headBranch,
        ?int $headRepoId = null,
        string $baseCommit = '',
        string $headCommit = '',
    ): PullRequestModel {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $number = $this->getNextPullRequestNumber($repoId);
        $headRepo = $headRepoId ?? $repoId;

        $stmt = $this->getPdo()->prepare(
            'INSERT INTO pull_requests (
                repo_id, number, title, description,
                base_ref, base_commit, head_repo_id, head_ref, head_commit,
                user_id, status, created_at, updated_at
            ) VALUES (
                :repo_id, :number, :title, :description,
                :base_ref, :base_commit, :head_repo_id, :head_ref, :head_commit,
                :user_id, :status, :created_at, :updated_at
            )'
        );
        $stmt->execute([
            'repo_id' => $repoId,
            'number' => $number,
            'title' => $title,
            'description' => $description,
            'base_ref' => $baseBranch,
            'base_commit' => $baseCommit,
            'head_repo_id' => $headRepo,
            'head_ref' => $headBranch,
            'head_commit' => $headCommit,
            'user_id' => $userId,
            'status' => PullRequestModel::STATE_OPEN,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->getPdo()->lastInsertId();

        $user = $this->findUserById($userId);
        $authorUsername = $user ? $user->username : null;

        return new PullRequestModel(
            id: $id,
            repoId: $repoId,
            number: $number,
            title: $title,
            body: $description,
            state: PullRequestModel::STATE_OPEN,
            authorUsername: $authorUsername,
            headBranch: $headBranch,
            baseBranch: $baseBranch,
            createdAt: new \DateTimeImmutable($now),
            updatedAt: new \DateTimeImmutable($now),
            headRepoId: $headRepo,
            userId: $userId,
            baseCommit: $baseCommit,
            headCommit: $headCommit,
        );
    }

    public function findPullRequestByNumber(int $repoId, int $number): ?PullRequestModel
    {
        $stmt = $this->getPdo()->prepare(
            'SELECT pr.*, u.username AS author_username, mb.username AS merged_by_username
             FROM pull_requests pr
             LEFT JOIN users u ON u.id = pr.user_id
             LEFT JOIN users mb ON mb.id = pr.merged_by_id
             WHERE pr.repo_id = :repo_id AND pr.number = :number LIMIT 1'
        );
        $stmt->execute(['repo_id' => $repoId, 'number' => $number]);
        $row = $stmt->fetch();

        return false !== $row ? $this->hydratePullRequest($row) : null;
    }

    public function findPullRequestById(int $id): ?PullRequestModel
    {
        $stmt = $this->getPdo()->prepare(
            'SELECT pr.*, u.username AS author_username, mb.username AS merged_by_username
             FROM pull_requests pr
             LEFT JOIN users u ON u.id = pr.user_id
             LEFT JOIN users mb ON mb.id = pr.merged_by_id
             WHERE pr.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return false !== $row ? $this->hydratePullRequest($row) : null;
    }

    /**
     * @return list<PullRequestModel>
     */
    public function listPullRequests(int $repoId, ?string $status = null): array
    {
        $sql = 'SELECT pr.*, u.username AS author_username, mb.username AS merged_by_username
                FROM pull_requests pr
                LEFT JOIN users u ON u.id = pr.user_id
                LEFT JOIN users mb ON mb.id = pr.merged_by_id
                WHERE pr.repo_id = :repo_id';
        $params = ['repo_id' => $repoId];

        if (null !== $status && '' !== $status) {
            $sql .= ' AND pr.status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY pr.number DESC';

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $prs = [];
        foreach ($rows as $row) {
            $prs[] = $this->hydratePullRequest($row);
        }

        return $prs;
    }

    public function updatePullRequestMeta(int $prId, string $title, string $description): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->getPdo()->prepare(
            'UPDATE pull_requests SET title = :title, description = :description, updated_at = :now WHERE id = :id'
        );
        $stmt->execute(['title' => $title, 'description' => $description, 'now' => $now, 'id' => $prId]);
    }

    public function mergePullRequest(int $prId, int $mergedById, ?string $mergeCommit = null): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->getPdo()->prepare(
            'UPDATE pull_requests
             SET status = :status, merge_commit = :merge_commit, merged_by_id = :merged_by_id, merged_at = :now, updated_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => PullRequestModel::STATE_MERGED,
            'merge_commit' => $mergeCommit,
            'merged_by_id' => $mergedById,
            'now' => $now,
            'id' => $prId,
        ]);
    }

    public function closePullRequest(int $prId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->getPdo()->prepare(
            'UPDATE pull_requests SET status = :status, closed_at = :now, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            'status' => PullRequestModel::STATE_CLOSED,
            'now' => $now,
            'id' => $prId,
        ]);
    }

    public function reopenPullRequest(int $prId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->getPdo()->prepare(
            'UPDATE pull_requests SET status = :status, closed_at = NULL, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            'status' => PullRequestModel::STATE_OPEN,
            'now' => $now,
            'id' => $prId,
        ]);
    }

    private function hydratePullRequest(array $row): PullRequestModel
    {
        return new PullRequestModel(
            id: (int) $row['id'],
            repoId: (int) $row['repo_id'],
            number: (int) $row['number'],
            title: (string) $row['title'],
            body: (string) ($row['description'] ?? ''),
            state: (string) $row['status'],
            authorUsername: isset($row['author_username']) ? (string) $row['author_username'] : null,
            headBranch: (string) $row['head_ref'],
            baseBranch: (string) $row['base_ref'],
            mergedBy: isset($row['merged_by_username']) ? (string) $row['merged_by_username'] : null,
            mergedAt: !empty($row['merged_at']) ? new \DateTimeImmutable((string) $row['merged_at']) : null,
            closedAt: !empty($row['closed_at']) ? new \DateTimeImmutable((string) $row['closed_at']) : null,
            createdAt: !empty($row['created_at']) ? new \DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: !empty($row['updated_at']) ? new \DateTimeImmutable((string) $row['updated_at']) : null,
            headRepoId: isset($row['head_repo_id']) ? (int) $row['head_repo_id'] : null,
            userId: isset($row['user_id']) ? (int) $row['user_id'] : null,
            baseCommit: (string) ($row['base_commit'] ?? ''),
            headCommit: (string) ($row['head_commit'] ?? ''),
            mergeCommit: isset($row['merge_commit']) ? (string) $row['merge_commit'] : null,
        );
    }

    // -------------------------------------------------------------------------
    // Pull Request Comments
    // -------------------------------------------------------------------------

    public function addComment(
        int $prId,
        int $userId,
        string $body,
        ?string $commitSha = null,
        ?string $filePath = null,
        ?int $line = null,
    ): PullRequestCommentModel {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->getPdo()->prepare(
            'INSERT INTO pull_request_comments (pull_request_id, user_id, body, commit_sha, file_path, line, created_at, updated_at)
             VALUES (:pr_id, :user_id, :body, :commit_sha, :file_path, :line, :now, :now)'
        );
        $stmt->execute([
            'pr_id' => $prId,
            'user_id' => $userId,
            'body' => $body,
            'commit_sha' => $commitSha,
            'file_path' => $filePath,
            'line' => $line,
            'now' => $now,
        ]);

        $id = (int) $this->getPdo()->lastInsertId();
        $user = $this->findUserById($userId);

        return new PullRequestCommentModel(
            id: $id,
            prId: $prId,
            authorUsername: $user ? $user->username : null,
            body: $body,
            commitSha: $commitSha,
            filePath: $filePath,
            line: $line,
            createdAt: new \DateTimeImmutable($now),
            updatedAt: new \DateTimeImmutable($now),
            userId: $userId,
        );
    }

    /**
     * @return list<PullRequestCommentModel>
     */
    public function listComments(int $prId): array
    {
        $stmt = $this->getPdo()->prepare(
            'SELECT c.*, u.username AS author_username
             FROM pull_request_comments c
             LEFT JOIN users u ON u.id = c.user_id
             WHERE c.pull_request_id = :pr_id
             ORDER BY c.created_at ASC, c.id ASC'
        );
        $stmt->execute(['pr_id' => $prId]);
        $rows = $stmt->fetchAll();

        $comments = [];
        foreach ($rows as $row) {
            $comments[] = new PullRequestCommentModel(
                id: (int) $row['id'],
                prId: (int) $row['pull_request_id'],
                authorUsername: isset($row['author_username']) ? (string) $row['author_username'] : null,
                body: (string) $row['body'],
                commitSha: isset($row['commit_sha']) ? (string) $row['commit_sha'] : null,
                filePath: isset($row['file_path']) ? (string) $row['file_path'] : null,
                line: isset($row['line']) ? (int) $row['line'] : null,
                createdAt: !empty($row['created_at']) ? new \DateTimeImmutable((string) $row['created_at']) : null,
                updatedAt: !empty($row['updated_at']) ? new \DateTimeImmutable((string) $row['updated_at']) : null,
                userId: (int) $row['user_id'],
            );
        }

        return $comments;
    }

    // -------------------------------------------------------------------------
    // Webhooks
    // -------------------------------------------------------------------------

    /**
     * @param list<string|int> $events
     */
    public function createWebhook(
        int $repoId,
        string $url,
        string $secret,
        string $contentType = 'json',
        bool $active = true,
        array $events = [],
    ): WebhookModel {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $ct = 'form' === $contentType ? 2 : 1;

        $stmt = $this->getPdo()->prepare(
            'INSERT INTO webhooks (repo_id, url, secret, content_type, active, created_at, updated_at)
             VALUES (:repo_id, :url, :secret, :content_type, :active, :now, :now)'
        );
        $stmt->execute([
            'repo_id' => $repoId,
            'url' => $url,
            'secret' => $secret,
            'content_type' => $ct,
            'active' => $active ? 1 : 0,
            'now' => $now,
        ]);

        $id = (int) $this->getPdo()->lastInsertId();

        foreach ($events as $event) {
            $evStmt = $this->getPdo()->prepare(
                'INSERT INTO webhook_events (webhook_id, event, created_at) VALUES (:wid, :event, :now)'
            );
            $evStmt->execute([
                'wid' => $id,
                'event' => is_numeric($event) ? (int) $event : crc32((string) $event),
                'now' => $now,
            ]);
        }

        return new WebhookModel(
            id: $id,
            repoId: $repoId,
            url: $url,
            secret: $secret,
            contentType: $contentType,
            active: $active,
            events: $events,
            createdAt: new \DateTimeImmutable($now),
            updatedAt: new \DateTimeImmutable($now),
        );
    }

    public function findWebhookById(int $id): ?WebhookModel
    {
        $stmt = $this->getPdo()->prepare('SELECT * FROM webhooks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return false !== $row ? $this->hydrateWebhook($row) : null;
    }

    /**
     * @return list<WebhookModel>
     */
    public function listWebhooks(int $repoId): array
    {
        $stmt = $this->getPdo()->prepare('SELECT * FROM webhooks WHERE repo_id = :repo_id ORDER BY id ASC');
        $stmt->execute(['repo_id' => $repoId]);
        $rows = $stmt->fetchAll();

        $webhooks = [];
        foreach ($rows as $row) {
            $webhooks[] = $this->hydrateWebhook($row);
        }

        return $webhooks;
    }

    public function deleteWebhook(int $id): bool
    {
        $stmt = $this->getPdo()->prepare('DELETE FROM webhooks WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function recordDelivery(
        string $deliveryId,
        int $webhookId,
        int|string $event,
        string $url,
        string $method,
        ?string $error,
        string $reqHeaders,
        string $reqBody,
        int $status,
        string $resHeaders,
        string $resBody,
    ): void {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $eventNum = is_numeric($event) ? (int) $event : crc32((string) $event);

        $stmt = $this->getPdo()->prepare(
            'INSERT INTO webhook_deliveries (
                id, webhook_id, event, request_url, request_method, request_error,
                request_headers, request_body, response_status, response_headers, response_body, created_at
            ) VALUES (
                :id, :webhook_id, :event, :url, :method, :error,
                :req_headers, :req_body, :status, :res_headers, :res_body, :now
            )'
        );
        $stmt->execute([
            'id' => $deliveryId,
            'webhook_id' => $webhookId,
            'event' => $eventNum,
            'url' => $url,
            'method' => $method,
            'error' => $error,
            'req_headers' => $reqHeaders,
            'req_body' => $reqBody,
            'status' => $status,
            'res_headers' => $resHeaders,
            'res_body' => $resBody,
            'now' => $now,
        ]);
    }

    /**
     * @return list<array>
     */
    public function listDeliveries(int $webhookId): array
    {
        $stmt = $this->getPdo()->prepare('SELECT * FROM webhook_deliveries WHERE webhook_id = :wid ORDER BY created_at DESC');
        $stmt->execute(['wid' => $webhookId]);

        return $stmt->fetchAll();
    }

    private function hydrateWebhook(array $row): WebhookModel
    {
        $wid = (int) $row['id'];
        $evStmt = $this->getPdo()->prepare('SELECT event FROM webhook_events WHERE webhook_id = :wid');
        $evStmt->execute(['wid' => $wid]);
        $events = $evStmt->fetchAll(\PDO::FETCH_COLUMN);

        return new WebhookModel(
            id: $wid,
            repoId: (int) $row['repo_id'],
            url: (string) $row['url'],
            secret: (string) $row['secret'],
            contentType: 2 === (int) $row['content_type'] ? 'form' : 'json',
            active: (bool) $row['active'],
            events: $events,
            createdAt: !empty($row['created_at']) ? new \DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: !empty($row['updated_at']) ? new \DateTimeImmutable((string) $row['updated_at']) : null,
        );
    }

    public function setRepoPrivate(int $repoId, bool $private): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->getPdo()->prepare('UPDATE repos SET private = :private, updated_at = :now WHERE id = :id');
        $stmt->execute(['private' => $private ? 1 : 0, 'now' => $now, 'id' => $repoId]);
    }

    public function setRepoHidden(int $repoId, bool $hidden): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->getPdo()->prepare('UPDATE repos SET hidden = :hidden, updated_at = :now WHERE id = :id');
        $stmt->execute(['hidden' => $hidden ? 1 : 0, 'now' => $now, 'id' => $repoId]);
    }

    public function setRepoDescription(int $repoId, string $description): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->getPdo()->prepare('UPDATE repos SET description = :description, updated_at = :now WHERE id = :id');
        $stmt->execute(['description' => $description, 'now' => $now, 'id' => $repoId]);
    }

    public function setRepoProjectName(int $repoId, string $projectName): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->getPdo()->prepare('UPDATE repos SET project_name = :project_name, updated_at = :now WHERE id = :id');
        $stmt->execute(['project_name' => $projectName, 'now' => $now, 'id' => $repoId]);
    }

    public function setRepoMirror(int $repoId, bool $mirror): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->getPdo()->prepare('UPDATE repos SET mirror = :mirror, updated_at = :now WHERE id = :id');
        $stmt->execute(['mirror' => $mirror ? 1 : 0, 'now' => $now, 'id' => $repoId]);
    }

    public function renameRepo(int $repoId, string $newName): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->getPdo()->prepare('UPDATE repos SET name = :name, updated_at = :now WHERE id = :id');
        $stmt->execute(['name' => strtolower(trim($newName)), 'now' => $now, 'id' => $repoId]);
    }

    public function setUserAdmin(int $userId, bool $isAdmin): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->getPdo()->prepare('UPDATE users SET admin = :admin, updated_at = :now WHERE id = :id');
        $stmt->execute(['admin' => $isAdmin ? 1 : 0, 'now' => $now, 'id' => $userId]);
    }

    public function setUsername(int $userId, string $newUsername): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->getPdo()->prepare('UPDATE users SET username = :username, updated_at = :now WHERE id = :id');
        $stmt->execute(['username' => strtolower(trim($newUsername)), 'now' => $now, 'id' => $userId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function createAccessToken(int $userId, string $name, ?string $token = null, ?\DateTimeInterface $expiresAt = null): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $generatedToken = $token ?? ('sat_'.bin2hex(random_bytes(20)));
        $expiresAtStr = $expiresAt?->format('Y-m-d H:i:s');

        $stmt = $this->getPdo()->prepare(
            'INSERT INTO access_tokens (token, name, user_id, expires_at, created_at, updated_at)
             VALUES (:token, :name, :user_id, :expires_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            'token' => $generatedToken,
            'name' => $name,
            'user_id' => $userId,
            'expires_at' => $expiresAtStr,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->getPdo()->lastInsertId();

        return [
            'id' => $id,
            'token' => $generatedToken,
            'name' => $name,
            'user_id' => $userId,
            'expires_at' => $expiresAtStr,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAccessTokens(int $userId): array
    {
        $stmt = $this->getPdo()->prepare('SELECT * FROM access_tokens WHERE user_id = :user_id ORDER BY id ASC');
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function deleteAccessToken(int $userId, int $id): bool
    {
        $stmt = $this->getPdo()->prepare('DELETE FROM access_tokens WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function deletePullRequest(int $prId): bool
    {
        $stmt = $this->getPdo()->prepare('DELETE FROM pull_requests WHERE id = :id');
        $stmt->execute(['id' => $prId]);

        return $stmt->rowCount() > 0;
    }
}
