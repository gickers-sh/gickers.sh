<?php

declare(strict_types=1);

namespace App\Service\Database;

/**
 * DatabaseManager manages the PDO connection and schema migrations.
 */
class DatabaseManager
{
    private ?\PDO $pdo = null;
    private string $dsn;
    private ?string $username;
    private ?string $password;
    private array $options;

    private string $dataPath;

    public function __construct(
        ?string $dsn = null,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
        ?string $dataPath = null,
    ) {
        if (null === $dataPath || '' === $dataPath) {
            $envPath = (string) (getenv('SOFT_SERVE_DATA_PATH') ?: (getenv('DATA_PATH') ?: ''));
            $this->dataPath = '' !== $envPath ? rtrim($envPath, '/') : dirname(__DIR__, 3).'/.data';
        } else {
            $this->dataPath = rtrim($dataPath, '/');
        }

        if (null === $dsn || '' === $dsn) {
            if (!is_dir($this->dataPath)) {
                @mkdir($this->dataPath, 0755, true);
            }
            if (is_dir($this->dataPath)) {
                $dsn = 'sqlite:'.$this->dataPath.'/soft-serve.db';
            } else {
                $dsn = 'sqlite::memory:';
            }
        }

        $this->dsn = $dsn;
        $this->username = $username;
        $this->password = $password;
        $this->options = $options;
    }

    public function getDataPath(): string
    {
        return $this->dataPath;
    }

    public function getReposPath(): string
    {
        $reposDir = $this->dataPath.'/repos';
        if (!is_dir($reposDir)) {
            @mkdir($reposDir, 0755, true);
        }

        return $reposDir;
    }

    public function isPgsql(?\PDO $db = null): bool
    {
        $conn = $db ?? $this->getConnection();
        $driver = $conn->getAttribute(\PDO::ATTR_DRIVER_NAME);

        return 'pgsql' === $driver;
    }

    /**
     * Ensures schema migrations are applied and initial admin user & settings exist
     * (matching Soft Serve 0001_create_tables.go seeding when running on a real server instance).
     */
    public function ensureBootstrapped(): void
    {
        $this->migrate();
        $pdo = $this->getConnection();
        $isPgsql = $this->isPgsql($pdo);

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Seed default settings if absent
        $defaultSettings = [
            'allow_keyless' => 'true',
            'anon_access' => 'read-only',
            'init' => 'true',
        ];
        $settingSql = $isPgsql
         ? 'INSERT INTO settings (key, value, created_at, updated_at) VALUES (:key, :value, :now, :now) ON CONFLICT (key) DO NOTHING'
         : 'INSERT OR IGNORE INTO settings (key, value, created_at, updated_at) VALUES (:key, :value, :now, :now)';
        $stmtSetting = $pdo->prepare($settingSql);
        foreach ($defaultSettings as $k => $v) {
            $stmtSetting->execute(['key' => $k, 'value' => $v, 'now' => $now]);
        }

        // Seed default admin user (ID 1) if no users exist yet
        $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if (0 === $userCount) {
            $userSql = $isPgsql
             ? 'INSERT INTO users (username, admin, created_at, updated_at) VALUES (:username, true, :now, :now) ON CONFLICT (username) DO NOTHING'
             : 'INSERT OR IGNORE INTO users (username, admin, created_at, updated_at) VALUES (:username, 1, :now, :now)';
            $stmtUser = $pdo->prepare($userSql);
            $stmtUser->execute(['username' => 'admin', 'now' => $now]);
            $adminId = (int) $pdo->lastInsertId();
            if (0 === $adminId) {
                $adminId = 1;
            }

            // Seed admin keys from SOFT_SERVE_INITIAL_ADMIN_KEYS, SOFT_SERVE_INITIAL_ADMIN_KEY, or ~/.ssh/id_*.pub
            $rawKeys = [];
            $envKeys = (string) (getenv('SOFT_SERVE_INITIAL_ADMIN_KEYS') ?: (getenv('SOFT_SERVE_INITIAL_ADMIN_KEY') ?: ''));
            if ('' !== $envKeys) {
                foreach (preg_split('/\r?\n/', $envKeys) ?: [] as $line) {
                    $line = trim($line);
                    if ('' !== $line) {
                        $rawKeys[] = $line;
                    }
                }
            } else {
                $home = (string) (getenv('HOME') ?: '');
                if ('' !== $home) {
                    foreach (['id_ed25519.pub', 'id_rsa.pub'] as $pubFile) {
                        $path = $home.'/.ssh/'.$pubFile;
                        if (is_file($path)) {
                            $content = trim((string) @file_get_contents($path));
                            if ('' !== $content) {
                                $rawKeys[] = $content;
                                break;
                            }
                        }
                    }
                }
            }

            $keySql = $isPgsql
             ? 'INSERT INTO public_keys (user_id, public_key, created_at, updated_at) VALUES (:user_id, :key, :now, :now) ON CONFLICT (public_key) DO NOTHING'
             : 'INSERT OR IGNORE INTO public_keys (user_id, public_key, created_at, updated_at) VALUES (:user_id, :key, :now, :now)';
            $stmtKey = $pdo->prepare($keySql);
            foreach ($rawKeys as $rk) {
                // Normalize to "<type> <base64>" or full line
                $stmtKey->execute(['user_id' => $adminId, 'key' => $rk, 'now' => $now]);
            }
        }
    }

    public function getConnection(): \PDO
    {
        if (null === $this->pdo) {
            $defaultOptions = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $options = array_replace($defaultOptions, $this->options);
            $this->pdo = new \PDO($this->dsn, $this->username, $this->password, $options);

            if (str_starts_with($this->dsn, 'sqlite:')) {
                $this->pdo->exec('PRAGMA foreign_keys = ON;');
                $this->pdo->exec('PRAGMA busy_timeout = 5000;');
            }
        }

        return $this->pdo;
    }

    /**
     * Executes pending migrations 1 through 5.
     *
     * @return int Number of newly applied migrations
     */
    private function ensureMigrationTables(\PDO $pdo): void
    {
        if ($this->isPgsql($pdo)) {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    version INTEGER PRIMARY KEY,
                    name TEXT NOT NULL,
                    applied_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS migrations (
                    id SERIAL PRIMARY KEY,
                    name TEXT NOT NULL,
                    version INTEGER NOT NULL UNIQUE
                );'
            );
        } else {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    version INTEGER PRIMARY KEY,
                    name TEXT NOT NULL,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    version INTEGER NOT NULL UNIQUE
                );'
            );
        }
    }

    /**
     * @return array<int, array{name: string, up: \Closure(\PDO): void, down: \Closure(\PDO): void}>
     */
    private function getMigrationsList(): array
    {
        return [
            1 => [
                'name' => 'create_tables',
                'up' => fn (\PDO $db) => $this->migration1CreateTables($db),
                'down' => fn (\PDO $db) => $this->rollback1CreateTables($db),
            ],
            2 => [
                'name' => 'webhooks',
                'up' => fn (\PDO $db) => $this->migration2Webhooks($db),
                'down' => fn (\PDO $db) => $this->rollback2Webhooks($db),
            ],
            3 => [
                'name' => 'migrate_lfs_objects',
                'up' => fn (\PDO $db) => $this->migration3LfsObjects($db),
                'down' => fn (\PDO $db) => $this->rollback3LfsObjects($db),
            ],
            4 => [
                'name' => 'repo_fork',
                'up' => fn (\PDO $db) => $this->migration4RepoFork($db),
                'down' => fn (\PDO $db) => $this->rollback4RepoFork($db),
            ],
            5 => [
                'name' => 'pull_requests',
                'up' => fn (\PDO $db) => $this->migration5PullRequests($db),
                'down' => fn (\PDO $db) => $this->rollback5PullRequests($db),
            ],
        ];
    }

    /**
     * Executes pending migrations 1 through 5.
     *
     * @return int Number of newly applied migrations
     */
    public function migrate(): int
    {
        $pdo = $this->getConnection();
        $this->ensureMigrationTables($pdo);

        $currentVersion = $this->getCurrentVersion();
        $appliedCount = 0;
        $migrations = $this->getMigrationsList();

        foreach ($migrations as $version => $migration) {
            if ($version > $currentVersion) {
                $inTx = $pdo->inTransaction();
                if (!$inTx) {
                    $pdo->beginTransaction();
                }

                try {
                    $migration['up']($pdo);

                    $stmt = $pdo->prepare(
                        'INSERT INTO schema_migrations (version, name, applied_at)
                         VALUES (:version, :name, :applied_at)'
                    );
                    $stmt->execute([
                        'version' => $version,
                        'name' => $migration['name'],
                        'applied_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ]);

                    try {
                        $stmtGo = $pdo->prepare(
                            'INSERT INTO migrations (name, version) VALUES (:name, :version)'
                        );
                        $stmtGo->execute([
                            'name' => $migration['name'],
                            'version' => $version,
                        ]);
                    } catch (\PDOException) {
                    }

                    if (!$inTx) {
                        $pdo->commit();
                    }
                    ++$appliedCount;
                } catch (\Throwable $e) {
                    if (!$inTx && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }
        }

        return $appliedCount;
    }

    /**
     * Rolls back the highest applied migration step.
     *
     * @return int The version number of the migration that was rolled back
     *
     * @throws \RuntimeException If there are no migrations to rollback
     */
    public function rollback(): int
    {
        $pdo = $this->getConnection();
        $this->ensureMigrationTables($pdo);

        $currentVersion = $this->getCurrentVersion();
        $migrations = $this->getMigrationsList();

        if ($currentVersion <= 0 || !isset($migrations[$currentVersion])) {
            throw new \RuntimeException('there are no migrations to rollback');
        }

        $migration = $migrations[$currentVersion];

        $inTx = $pdo->inTransaction();
        if (!$inTx) {
            $pdo->beginTransaction();
        }

        try {
            $migration['down']($pdo);

            $stmt = $pdo->prepare('DELETE FROM schema_migrations WHERE version = :version');
            $stmt->execute(['version' => $currentVersion]);

            try {
                $stmtGo = $pdo->prepare('DELETE FROM migrations WHERE version = :version');
                $stmtGo->execute(['version' => $currentVersion]);
            } catch (\PDOException) {
            }

            if (!$inTx) {
                $pdo->commit();
            }

            return $currentVersion;
        } catch (\Throwable $e) {
            if (!$inTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getCurrentVersion(): int
    {
        $pdo = $this->getConnection();

        try {
            $stmt = $pdo->query('SELECT MAX(version) AS max_version FROM schema_migrations');
            $row = $stmt ? $stmt->fetch() : false;
            if (isset($row['max_version']) && null !== $row['max_version']) {
                return (int) $row['max_version'];
            }
        } catch (\PDOException) {
        }

        try {
            $stmt = $pdo->query('SELECT MAX(version) AS max_version FROM migrations');
            $row = $stmt ? $stmt->fetch() : false;

            return (int) ($row['max_version'] ?? 0);
        } catch (\PDOException) {
            return 0;
        }
    }

    public function reset(): void
    {
        $pdo = $this->getConnection();
        if (str_starts_with($this->dsn, 'sqlite:')) {
            $pdo->exec('PRAGMA foreign_keys = OFF;');
            $tables = $pdo->query(
                "SELECT name FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%'"
            )->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                $pdo->exec(sprintf('DROP TABLE IF EXISTS "%s"; DROP VIEW IF EXISTS "%s"', $table, $table));
            }
            $pdo->exec('PRAGMA foreign_keys = ON;');
        }
    }

    private function migration1CreateTables(\PDO $db): void
    {
        if ($this->isPgsql($db)) {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS settings (
                    id SERIAL PRIMARY KEY,
                    key TEXT NOT NULL UNIQUE,
                    value TEXT NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL
                );

                CREATE TABLE IF NOT EXISTS users (
                    id SERIAL PRIMARY KEY,
                    username TEXT NOT NULL UNIQUE,
                    admin BOOLEAN NOT NULL DEFAULT FALSE,
                    password TEXT,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL
                );

                CREATE TABLE IF NOT EXISTS public_keys (
                    id SERIAL PRIMARY KEY,
                    user_id INTEGER NOT NULL,
                    public_key TEXT NOT NULL UNIQUE,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL,
                    CONSTRAINT user_id_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
                );

                CREATE TABLE IF NOT EXISTS repos (
                    id SERIAL PRIMARY KEY,
                    name TEXT NOT NULL UNIQUE,
                    project_name TEXT NOT NULL,
                    description TEXT NOT NULL,
                    private BOOLEAN NOT NULL DEFAULT FALSE,
                    mirror BOOLEAN NOT NULL DEFAULT FALSE,
                    hidden BOOLEAN NOT NULL DEFAULT FALSE,
                    user_id INTEGER NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL,
                    CONSTRAINT user_id_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
                );

                CREATE TABLE IF NOT EXISTS collabs (
                    id SERIAL PRIMARY KEY,
                    user_id INTEGER NOT NULL,
                    repo_id INTEGER NOT NULL,
                    access_level INTEGER NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL,
                    UNIQUE (user_id, repo_id),
                    CONSTRAINT user_id_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT repo_id_fk FOREIGN KEY(repo_id) REFERENCES repos(id) ON DELETE CASCADE ON UPDATE CASCADE
                );

                CREATE OR REPLACE VIEW collab AS SELECT * FROM collabs;

                CREATE TABLE IF NOT EXISTS lfs_objects (
                    id SERIAL PRIMARY KEY,
                    oid TEXT NOT NULL,
                    size INTEGER NOT NULL,
                    repo_id INTEGER NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL,
                    UNIQUE (oid, repo_id),
                    CONSTRAINT repo_id_fk FOREIGN KEY(repo_id) REFERENCES repos(id) ON DELETE CASCADE ON UPDATE CASCADE
                );

                CREATE TABLE IF NOT EXISTS lfs_locks (
                    id SERIAL PRIMARY KEY,
                    repo_id INTEGER NOT NULL,
                    user_id INTEGER NOT NULL,
                    path TEXT NOT NULL,
                    refname TEXT,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL,
                    UNIQUE (repo_id, path),
                    CONSTRAINT repo_id_fk FOREIGN KEY(repo_id) REFERENCES repos(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT user_id_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
                );

                CREATE TABLE IF NOT EXISTS access_tokens (
                    id SERIAL PRIMARY KEY,
                    token TEXT NOT NULL UNIQUE,
                    name TEXT NOT NULL,
                    user_id INTEGER NOT NULL,
                    expires_at TIMESTAMP,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL,
                    CONSTRAINT user_id_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
                );'
            );
        } else {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS settings (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    key TEXT NOT NULL UNIQUE,
                    value TEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL
                );

                CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL UNIQUE,
                    admin BOOLEAN NOT NULL DEFAULT 0,
                    password TEXT,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL
                );

                CREATE TABLE IF NOT EXISTS public_keys (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    public_key TEXT NOT NULL UNIQUE,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL,
                    CONSTRAINT user_id_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
                );

                CREATE TABLE IF NOT EXISTS repos (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    project_name TEXT NOT NULL,
                    description TEXT NOT NULL,
                    private BOOLEAN NOT NULL DEFAULT 0,
                    mirror BOOLEAN NOT NULL DEFAULT 0,
                    hidden BOOLEAN NOT NULL DEFAULT 0,
                    user_id INTEGER NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL,
                    CONSTRAINT user_id_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
                );

                CREATE TABLE IF NOT EXISTS collabs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    repo_id INTEGER NOT NULL,
                    access_level INTEGER NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL,
                    UNIQUE (user_id, repo_id),
                    CONSTRAINT user_id_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT repo_id_fk FOREIGN KEY(repo_id) REFERENCES repos(id) ON DELETE CASCADE ON UPDATE CASCADE
                );

                CREATE VIEW IF NOT EXISTS collab AS SELECT * FROM collabs;

                CREATE TABLE IF NOT EXISTS lfs_objects (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    oid TEXT NOT NULL,
                    size INTEGER NOT NULL,
                    repo_id INTEGER NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL,
                    UNIQUE (oid, repo_id),
                    CONSTRAINT repo_id_fk FOREIGN KEY(repo_id) REFERENCES repos(id) ON DELETE CASCADE ON UPDATE CASCADE
                );

                CREATE TABLE IF NOT EXISTS lfs_locks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    repo_id INTEGER NOT NULL,
                    user_id INTEGER NOT NULL,
                    path TEXT NOT NULL,
                    refname TEXT,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL,
                    UNIQUE (repo_id, path),
                    CONSTRAINT repo_id_fk FOREIGN KEY(repo_id) REFERENCES repos(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT user_id_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
                );

                CREATE TABLE IF NOT EXISTS access_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    token TEXT NOT NULL UNIQUE,
                    name TEXT NOT NULL,
                    user_id INTEGER NOT NULL,
                    expires_at DATETIME,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL,
                    CONSTRAINT user_id_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
                );'
            );
        }
    }

    private function rollback1CreateTables(\PDO $db): void
    {
        $db->exec(
            'DROP TABLE IF EXISTS access_tokens;
            DROP TABLE IF EXISTS lfs_locks;
            DROP TABLE IF EXISTS lfs_objects;
            DROP VIEW IF EXISTS collab;
            DROP TABLE IF EXISTS collabs;
            DROP TABLE IF EXISTS repos;
            DROP TABLE IF EXISTS public_keys;
            DROP TABLE IF EXISTS users;
            DROP TABLE IF EXISTS settings;'
        );
    }

    private function migration2Webhooks(\PDO $db): void
    {
        if ($this->isPgsql($db)) {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS webhooks (
                    id SERIAL PRIMARY KEY,
                    repo_id INTEGER NOT NULL,
                    url TEXT NOT NULL,
                    secret TEXT NOT NULL,
                    content_type INTEGER NOT NULL DEFAULT 1,
                    active BOOLEAN NOT NULL DEFAULT TRUE,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL,
                    UNIQUE (repo_id, url),
                    CONSTRAINT repo_id_fk FOREIGN KEY(repo_id) REFERENCES repos(id) ON DELETE CASCADE ON UPDATE CASCADE
                );

                CREATE TABLE IF NOT EXISTS webhook_events (
                    id SERIAL PRIMARY KEY,
                    webhook_id INTEGER NOT NULL,
                    event INTEGER NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (webhook_id, event),
                    CONSTRAINT webhook_id_fk FOREIGN KEY(webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE ON UPDATE CASCADE
                );

                CREATE TABLE IF NOT EXISTS webhook_deliveries (
                    id TEXT PRIMARY KEY,
                    webhook_id INTEGER NOT NULL,
                    event INTEGER NOT NULL,
                    request_url TEXT NOT NULL,
                    request_method TEXT NOT NULL,
                    request_error TEXT,
                    request_headers TEXT NOT NULL,
                    request_body TEXT NOT NULL,
                    response_status INTEGER NOT NULL,
                    response_headers TEXT NOT NULL,
                    response_body TEXT NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT webhook_id_fk FOREIGN KEY(webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE ON UPDATE CASCADE
                );'
            );
        } else {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS webhooks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    repo_id INTEGER NOT NULL,
                    url TEXT NOT NULL,
                    secret TEXT NOT NULL,
                    content_type INTEGER NOT NULL DEFAULT 1,
                    active BOOLEAN NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL,
                    UNIQUE (repo_id, url),
                    CONSTRAINT repo_id_fk FOREIGN KEY(repo_id) REFERENCES repos(id) ON DELETE CASCADE ON UPDATE CASCADE
                );

                CREATE TABLE IF NOT EXISTS webhook_events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    webhook_id INTEGER NOT NULL,
                    event INTEGER NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (webhook_id, event),
                    CONSTRAINT webhook_id_fk FOREIGN KEY(webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE ON UPDATE CASCADE
                );

                CREATE TABLE IF NOT EXISTS webhook_deliveries (
                    id TEXT PRIMARY KEY,
                    webhook_id INTEGER NOT NULL,
                    event INTEGER NOT NULL,
                    request_url TEXT NOT NULL,
                    request_method TEXT NOT NULL,
                    request_error TEXT,
                    request_headers TEXT NOT NULL,
                    request_body TEXT NOT NULL,
                    response_status INTEGER NOT NULL,
                    response_headers TEXT NOT NULL,
                    response_body TEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT webhook_id_fk FOREIGN KEY(webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE ON UPDATE CASCADE
                );'
            );
        }
    }

    private function rollback2Webhooks(\PDO $db): void
    {
        $db->exec(
            'DROP TABLE IF EXISTS webhook_deliveries;
            DROP TABLE IF EXISTS webhook_events;
            DROP TABLE IF EXISTS webhooks;'
        );
    }

    private function migration3LfsObjects(\PDO $db): void
    {
        try {
            $stmt = $db->query('SELECT id FROM repos');
            $repoIds = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
            foreach ($repoIds as $repoId) {
                $stmtObjs = $db->prepare('SELECT oid FROM lfs_objects WHERE repo_id = :repo_id');
                $stmtObjs->execute(['repo_id' => $repoId]);
                $oids = $stmtObjs->fetchAll(\PDO::FETCH_COLUMN);

                $objectsPath = $this->dataPath.'/lfs/'.$repoId.'/objects';
                if (!is_dir($objectsPath)) {
                    continue;
                }

                foreach ($oids as $oid) {
                    $oidStr = (string) $oid;
                    if (strlen($oidStr) < 5) {
                        continue;
                    }
                    $oldPath = $objectsPath.'/'.substr($oidStr, 0, 2).'/'.substr($oidStr, 2, 2).'/'.substr($oidStr, 4);
                    $newPath = $objectsPath.'/'.substr($oidStr, 0, 2).'/'.substr($oidStr, 2, 2).'/'.$oidStr;
                    if (is_file($oldPath)) {
                        @rename($oldPath, $newPath);
                    }
                }
            }
        } catch (\Throwable) {
            // Repos or lfs_objects may not exist yet or be empty
        }

        $db->exec('CREATE INDEX IF NOT EXISTS idx_lfs_objects_repo ON lfs_objects(repo_id);');
    }

    private function rollback3LfsObjects(\PDO $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_lfs_objects_repo;');
    }

    private function migration4RepoFork(\PDO $db): void
    {
        $cols = $this->getTableColumns($db, 'repos');
        if (!in_array('fork_id', $cols, true)) {
            $db->exec('ALTER TABLE repos ADD COLUMN fork_id INTEGER REFERENCES repos(id) ON DELETE SET NULL;');
        }
        if (!in_array('parent_repo_id', $cols, true)) {
            $db->exec('ALTER TABLE repos ADD COLUMN parent_repo_id INTEGER REFERENCES repos(id) ON DELETE SET NULL;');
        }
        if (!in_array('is_fork', $cols, true)) {
            $default = $this->isPgsql($db) ? 'FALSE' : '0';
            $db->exec(sprintf('ALTER TABLE repos ADD COLUMN is_fork BOOLEAN NOT NULL DEFAULT %s;', $default));
        }
    }

    private function rollback4RepoFork(\PDO $db): void
    {
        $cols = $this->getTableColumns($db, 'repos');
        foreach (['parent_repo_id', 'is_fork', 'fork_id'] as $col) {
            if (in_array($col, $cols, true)) {
                $db->exec(sprintf('ALTER TABLE repos DROP COLUMN %s;', $col));
            }
        }
    }

    private function migration5PullRequests(\PDO $db): void
    {
        if ($this->isPgsql($db)) {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS pull_requests (
                    id SERIAL PRIMARY KEY,
                    repo_id INTEGER NOT NULL,
                    number INTEGER NOT NULL,
                    title TEXT NOT NULL,
                    description TEXT NOT NULL DEFAULT '',
                    base_ref TEXT NOT NULL,
                    base_commit TEXT NOT NULL,
                    head_repo_id INTEGER NOT NULL,
                    head_ref TEXT NOT NULL,
                    head_commit TEXT NOT NULL,
                    user_id INTEGER NOT NULL,
                    status TEXT NOT NULL DEFAULT 'open',
                    merge_commit TEXT,
                    merged_by_id INTEGER,
                    merged_at TIMESTAMP,
                    closed_at TIMESTAMP,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL,
                    UNIQUE (repo_id, number),
                    CONSTRAINT repo_id_fk FOREIGN KEY(repo_id) REFERENCES repos(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT head_repo_id_fk FOREIGN KEY(head_repo_id) REFERENCES repos(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT user_id_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT merged_by_id_fk FOREIGN KEY(merged_by_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
                );

                CREATE INDEX IF NOT EXISTS idx_pull_requests_repo_status ON pull_requests(repo_id, status);

                CREATE TABLE IF NOT EXISTS pull_request_comments (
                    id SERIAL PRIMARY KEY,
                    pull_request_id INTEGER NOT NULL,
                    user_id INTEGER NOT NULL,
                    body TEXT NOT NULL,
                    commit_sha TEXT,
                    file_path TEXT,
                    line INTEGER,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL,
                    CONSTRAINT pr_id_fk FOREIGN KEY(pull_request_id) REFERENCES pull_requests(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT user_id_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
                );

                CREATE INDEX IF NOT EXISTS idx_pull_request_comments_pr ON pull_request_comments(pull_request_id);"
            );
        } else {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS pull_requests (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    repo_id INTEGER NOT NULL,
                    number INTEGER NOT NULL,
                    title TEXT NOT NULL,
                    description TEXT NOT NULL DEFAULT '',
                    base_ref TEXT NOT NULL,
                    base_commit TEXT NOT NULL,
                    head_repo_id INTEGER NOT NULL,
                    head_ref TEXT NOT NULL,
                    head_commit TEXT NOT NULL,
                    user_id INTEGER NOT NULL,
                    status TEXT NOT NULL DEFAULT 'open',
                    merge_commit TEXT,
                    merged_by_id INTEGER,
                    merged_at DATETIME,
                    closed_at DATETIME,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL,
                    UNIQUE (repo_id, number),
                    CONSTRAINT repo_id_fk FOREIGN KEY(repo_id) REFERENCES repos(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT head_repo_id_fk FOREIGN KEY(head_repo_id) REFERENCES repos(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT user_id_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT merged_by_id_fk FOREIGN KEY(merged_by_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
                );

                CREATE INDEX IF NOT EXISTS idx_pull_requests_repo_status ON pull_requests(repo_id, status);

                CREATE TABLE IF NOT EXISTS pull_request_comments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    pull_request_id INTEGER NOT NULL,
                    user_id INTEGER NOT NULL,
                    body TEXT NOT NULL,
                    commit_sha TEXT,
                    file_path TEXT,
                    line INTEGER,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL,
                    CONSTRAINT pr_id_fk FOREIGN KEY(pull_request_id) REFERENCES pull_requests(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT user_id_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
                );

                CREATE INDEX IF NOT EXISTS idx_pull_request_comments_pr ON pull_request_comments(pull_request_id);"
            );
        }
    }

    private function rollback5PullRequests(\PDO $db): void
    {
        $db->exec(
            'DROP TABLE IF EXISTS pull_request_comments;
            DROP TABLE IF EXISTS pull_requests;'
        );
    }

    /**
     * @return list<string>
     */
    private function getTableColumns(\PDO $db, string $table): array
    {
        try {
            if ($this->isPgsql($db)) {
                $stmt = $db->prepare('SELECT column_name FROM information_schema.columns WHERE table_name = :tbl');
                $stmt->execute(['tbl' => $table]);

                return $stmt->fetchAll(\PDO::FETCH_COLUMN);
            }

            $stmt = $db->query(sprintf('PRAGMA table_info("%s")', $table));
            if ($stmt) {
                return $stmt->fetchAll(\PDO::FETCH_COLUMN, 1);
            }
        } catch (\PDOException) {
        }

        return [];
    }
}
