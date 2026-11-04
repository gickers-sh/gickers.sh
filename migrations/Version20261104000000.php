<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial codes.sh schema (redesigned, see specs/symfony-modular-architecture/TECH.md §2.3).
 * Supports SQLite and PostgreSQL.
 */
final class Version20261104000000 extends AbstractMigration
{
    private const SQLITE = [
            'CREATE TABLE server_settings (name VARCHAR(64) NOT NULL, value CLOB NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (name))',
            'CREATE TABLE user_public_keys (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, algorithm VARCHAR(64) NOT NULL, key_data CLOB NOT NULL, fingerprint VARCHAR(128) NOT NULL, comment VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_66C8CFA8A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE UNIQUE INDEX UNIQ_66C8CFA8FC0B754A ON user_public_keys (fingerprint)',
            'CREATE INDEX IDX_66C8CFA8A76ED395 ON user_public_keys (user_id)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(64) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, updated_at DATETIME NOT NULL, is_admin BOOLEAN NOT NULL, created_at DATETIME NOT NULL)',
            'CREATE UNIQUE INDEX UNIQ_1483A5E9F85E0677 ON users (username)',
            'CREATE TABLE user_access_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, last_used_at DATETIME DEFAULT NULL, name VARCHAR(255) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_B65F29ECA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE UNIQUE INDEX UNIQ_B65F29ECB3BC57DA ON user_access_tokens (token_hash)',
            'CREATE INDEX IDX_B65F29ECA76ED395 ON user_access_tokens (user_id)',
            'CREATE TABLE repo_collaborators (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, updated_at DATETIME NOT NULL, access_level SMALLINT NOT NULL, created_at DATETIME NOT NULL, repo_id INTEGER NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_D90A18DEBD359B2D FOREIGN KEY (repo_id) REFERENCES repos (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D90A18DEA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE UNIQUE INDEX UNIQ_D90A18DEBD359B2DA76ED395 ON repo_collaborators (repo_id, user_id)',
            'CREATE INDEX IDX_D90A18DEBD359B2D ON repo_collaborators (repo_id)',
            'CREATE INDEX IDX_D90A18DEA76ED395 ON repo_collaborators (user_id)',
            'CREATE TABLE repos (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, next_pull_request_number INTEGER DEFAULT 1 NOT NULL, updated_at DATETIME NOT NULL, project_name VARCHAR(255) NOT NULL, description CLOB NOT NULL, is_private BOOLEAN NOT NULL, is_hidden BOOLEAN NOT NULL, is_mirror BOOLEAN NOT NULL, created_at DATETIME NOT NULL, owner_id INTEGER NOT NULL, fork_of_id INTEGER DEFAULT NULL, CONSTRAINT FK_36507C3D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_36507C3DBA9A3285 FOREIGN KEY (fork_of_id) REFERENCES repos (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE UNIQUE INDEX UNIQ_36507C3D5E237E06 ON repos (name)',
            'CREATE INDEX IDX_36507C3D7E3C61F9 ON repos (owner_id)',
            'CREATE INDEX IDX_36507C3DBA9A3285 ON repos (fork_of_id)',
            'CREATE TABLE pull_request_comments (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, updated_at DATETIME NOT NULL, body CLOB NOT NULL, commit_sha VARCHAR(64) DEFAULT NULL, file_path VARCHAR(1024) DEFAULT NULL, line INTEGER DEFAULT NULL, created_at DATETIME NOT NULL, pull_request_id INTEGER NOT NULL, author_id INTEGER NOT NULL, CONSTRAINT FK_3763FD6E4CE0BF7E FOREIGN KEY (pull_request_id) REFERENCES pull_requests (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3763FD6EF675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE INDEX IDX_3763FD6E4CE0BF7E ON pull_request_comments (pull_request_id)',
            'CREATE INDEX IDX_3763FD6EF675F31B ON pull_request_comments (author_id)',
            'CREATE TABLE pull_requests (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, status VARCHAR(16) NOT NULL, merge_commit VARCHAR(64) DEFAULT NULL, merged_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, updated_at DATETIME NOT NULL, number INTEGER NOT NULL, title VARCHAR(255) NOT NULL, description CLOB NOT NULL, base_ref VARCHAR(255) NOT NULL, base_commit VARCHAR(64) NOT NULL, head_ref VARCHAR(255) NOT NULL, head_commit VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, merged_by_id INTEGER DEFAULT NULL, repo_id INTEGER NOT NULL, head_repo_id INTEGER NOT NULL, author_id INTEGER NOT NULL, CONSTRAINT FK_2B30AB7C50FA8329 FOREIGN KEY (merged_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B30AB7CBD359B2D FOREIGN KEY (repo_id) REFERENCES repos (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B30AB7CE7FDFC3C FOREIGN KEY (head_repo_id) REFERENCES repos (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B30AB7CF675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE INDEX IDX_2B30AB7CBD359B2D7B00651C ON pull_requests (repo_id, status)',
            'CREATE UNIQUE INDEX UNIQ_2B30AB7CBD359B2D96901F54 ON pull_requests (repo_id, number)',
            'CREATE INDEX IDX_2B30AB7C50FA8329 ON pull_requests (merged_by_id)',
            'CREATE INDEX IDX_2B30AB7CBD359B2D ON pull_requests (repo_id)',
            'CREATE INDEX IDX_2B30AB7CE7FDFC3C ON pull_requests (head_repo_id)',
            'CREATE INDEX IDX_2B30AB7CF675F31B ON pull_requests (author_id)',
            'CREATE TABLE webhook_deliveries (id BLOB NOT NULL, event VARCHAR(64) NOT NULL, request_url VARCHAR(2048) NOT NULL, request_method VARCHAR(8) NOT NULL, request_headers CLOB NOT NULL, request_body CLOB NOT NULL, request_error CLOB DEFAULT NULL, response_status INTEGER DEFAULT NULL, response_headers CLOB DEFAULT NULL, response_body CLOB DEFAULT NULL, duration_ms INTEGER DEFAULT NULL, created_at DATETIME NOT NULL, webhook_id INTEGER NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_3681F32D5C9BA60B FOREIGN KEY (webhook_id) REFERENCES webhooks (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE INDEX IDX_3681F32D5C9BA60B8B8E8428 ON webhook_deliveries (webhook_id, created_at)',
            'CREATE INDEX IDX_3681F32D5C9BA60B ON webhook_deliveries (webhook_id)',
            'CREATE TABLE webhooks (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, events CLOB NOT NULL, updated_at DATETIME NOT NULL, url VARCHAR(2048) NOT NULL, secret VARCHAR(255) NOT NULL, content_type VARCHAR(8) NOT NULL, is_active BOOLEAN NOT NULL, created_at DATETIME NOT NULL, repo_id INTEGER NOT NULL, CONSTRAINT FK_998C4FDDBD359B2D FOREIGN KEY (repo_id) REFERENCES repos (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE UNIQUE INDEX UNIQ_998C4FDDBD359B2DF47645AE ON webhooks (repo_id, url)',
            'CREATE INDEX IDX_998C4FDDBD359B2D ON webhooks (repo_id)',
            'CREATE TABLE lfs_locks (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, path VARCHAR(1024) NOT NULL, ref_name VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, repo_id INTEGER NOT NULL, owner_id INTEGER NOT NULL, CONSTRAINT FK_5091253CBD359B2D FOREIGN KEY (repo_id) REFERENCES repos (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5091253C7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE UNIQUE INDEX UNIQ_5091253CBD359B2DB548B0F ON lfs_locks (repo_id, path)',
            'CREATE INDEX IDX_5091253CBD359B2D ON lfs_locks (repo_id)',
            'CREATE INDEX IDX_5091253C7E3C61F9 ON lfs_locks (owner_id)',
            'CREATE TABLE lfs_objects (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, oid CHAR(64) NOT NULL, size BIGINT NOT NULL, created_at DATETIME NOT NULL, repo_id INTEGER NOT NULL, CONSTRAINT FK_87EECCD3BD359B2D FOREIGN KEY (repo_id) REFERENCES repos (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE UNIQUE INDEX UNIQ_87EECCD3BD359B2D422A20A0 ON lfs_objects (repo_id, oid)',
            'CREATE INDEX IDX_87EECCD3BD359B2D ON lfs_objects (repo_id)',
            'CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)',
            'CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)',
    ];

    private const POSTGRESQL = [
            'CREATE TABLE server_settings (name VARCHAR(64) NOT NULL, value TEXT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (name))',
            'CREATE TABLE user_public_keys (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, algorithm VARCHAR(64) NOT NULL, key_data TEXT NOT NULL, fingerprint VARCHAR(128) NOT NULL, comment VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id))',
            'CREATE UNIQUE INDEX UNIQ_66C8CFA8FC0B754A ON user_public_keys (fingerprint)',
            'CREATE INDEX IDX_66C8CFA8A76ED395 ON user_public_keys (user_id)',
            'CREATE TABLE users (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, username VARCHAR(64) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, is_admin BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))',
            'CREATE UNIQUE INDEX UNIQ_1483A5E9F85E0677 ON users (username)',
            'CREATE TABLE user_access_tokens (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, name VARCHAR(255) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id))',
            'CREATE UNIQUE INDEX UNIQ_B65F29ECB3BC57DA ON user_access_tokens (token_hash)',
            'CREATE INDEX IDX_B65F29ECA76ED395 ON user_access_tokens (user_id)',
            'CREATE TABLE repo_collaborators (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, access_level SMALLINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, repo_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id))',
            'CREATE UNIQUE INDEX UNIQ_D90A18DEBD359B2DA76ED395 ON repo_collaborators (repo_id, user_id)',
            'CREATE INDEX IDX_D90A18DEBD359B2D ON repo_collaborators (repo_id)',
            'CREATE INDEX IDX_D90A18DEA76ED395 ON repo_collaborators (user_id)',
            'CREATE TABLE repos (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, name VARCHAR(255) NOT NULL, next_pull_request_number INT DEFAULT 1 NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_name VARCHAR(255) NOT NULL, description TEXT NOT NULL, is_private BOOLEAN NOT NULL, is_hidden BOOLEAN NOT NULL, is_mirror BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id INT NOT NULL, fork_of_id INT DEFAULT NULL, PRIMARY KEY (id))',
            'CREATE UNIQUE INDEX UNIQ_36507C3D5E237E06 ON repos (name)',
            'CREATE INDEX IDX_36507C3D7E3C61F9 ON repos (owner_id)',
            'CREATE INDEX IDX_36507C3DBA9A3285 ON repos (fork_of_id)',
            'CREATE TABLE pull_request_comments (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, body TEXT NOT NULL, commit_sha VARCHAR(64) DEFAULT NULL, file_path VARCHAR(1024) DEFAULT NULL, line INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, pull_request_id INT NOT NULL, author_id INT NOT NULL, PRIMARY KEY (id))',
            'CREATE INDEX IDX_3763FD6E4CE0BF7E ON pull_request_comments (pull_request_id)',
            'CREATE INDEX IDX_3763FD6EF675F31B ON pull_request_comments (author_id)',
            'CREATE TABLE pull_requests (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, status VARCHAR(16) NOT NULL, merge_commit VARCHAR(64) DEFAULT NULL, merged_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, number INT NOT NULL, title VARCHAR(255) NOT NULL, description TEXT NOT NULL, base_ref VARCHAR(255) NOT NULL, base_commit VARCHAR(64) NOT NULL, head_ref VARCHAR(255) NOT NULL, head_commit VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, merged_by_id INT DEFAULT NULL, repo_id INT NOT NULL, head_repo_id INT NOT NULL, author_id INT NOT NULL, PRIMARY KEY (id))',
            'CREATE INDEX IDX_2B30AB7CBD359B2D7B00651C ON pull_requests (repo_id, status)',
            'CREATE UNIQUE INDEX UNIQ_2B30AB7CBD359B2D96901F54 ON pull_requests (repo_id, number)',
            'CREATE INDEX IDX_2B30AB7C50FA8329 ON pull_requests (merged_by_id)',
            'CREATE INDEX IDX_2B30AB7CBD359B2D ON pull_requests (repo_id)',
            'CREATE INDEX IDX_2B30AB7CE7FDFC3C ON pull_requests (head_repo_id)',
            'CREATE INDEX IDX_2B30AB7CF675F31B ON pull_requests (author_id)',
            'CREATE TABLE webhook_deliveries (id UUID NOT NULL, event VARCHAR(64) NOT NULL, request_url VARCHAR(2048) NOT NULL, request_method VARCHAR(8) NOT NULL, request_headers JSON NOT NULL, request_body TEXT NOT NULL, request_error TEXT DEFAULT NULL, response_status INT DEFAULT NULL, response_headers JSON DEFAULT NULL, response_body TEXT DEFAULT NULL, duration_ms INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, webhook_id INT NOT NULL, PRIMARY KEY (id))',
            'CREATE INDEX IDX_3681F32D5C9BA60B8B8E8428 ON webhook_deliveries (webhook_id, created_at)',
            'CREATE INDEX IDX_3681F32D5C9BA60B ON webhook_deliveries (webhook_id)',
            'CREATE TABLE webhooks (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, events JSON NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, url VARCHAR(2048) NOT NULL, secret VARCHAR(255) NOT NULL, content_type VARCHAR(8) NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, repo_id INT NOT NULL, PRIMARY KEY (id))',
            'CREATE UNIQUE INDEX UNIQ_998C4FDDBD359B2DF47645AE ON webhooks (repo_id, url)',
            'CREATE INDEX IDX_998C4FDDBD359B2D ON webhooks (repo_id)',
            'CREATE TABLE lfs_locks (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, path VARCHAR(1024) NOT NULL, ref_name VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, repo_id INT NOT NULL, owner_id INT NOT NULL, PRIMARY KEY (id))',
            'CREATE UNIQUE INDEX UNIQ_5091253CBD359B2DB548B0F ON lfs_locks (repo_id, path)',
            'CREATE INDEX IDX_5091253CBD359B2D ON lfs_locks (repo_id)',
            'CREATE INDEX IDX_5091253C7E3C61F9 ON lfs_locks (owner_id)',
            'CREATE TABLE lfs_objects (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, oid CHAR(64) NOT NULL, size BIGINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, repo_id INT NOT NULL, PRIMARY KEY (id))',
            'CREATE UNIQUE INDEX UNIQ_87EECCD3BD359B2D422A20A0 ON lfs_objects (repo_id, oid)',
            'CREATE INDEX IDX_87EECCD3BD359B2D ON lfs_objects (repo_id)',
            'CREATE TABLE messenger_messages (id BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))',
            'CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)',
            'ALTER TABLE user_public_keys ADD CONSTRAINT FK_66C8CFA8A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE',
            'ALTER TABLE user_access_tokens ADD CONSTRAINT FK_B65F29ECA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE',
            'ALTER TABLE repo_collaborators ADD CONSTRAINT FK_D90A18DEBD359B2D FOREIGN KEY (repo_id) REFERENCES repos (id) ON DELETE CASCADE NOT DEFERRABLE',
            'ALTER TABLE repo_collaborators ADD CONSTRAINT FK_D90A18DEA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE',
            'ALTER TABLE repos ADD CONSTRAINT FK_36507C3D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE RESTRICT NOT DEFERRABLE',
            'ALTER TABLE repos ADD CONSTRAINT FK_36507C3DBA9A3285 FOREIGN KEY (fork_of_id) REFERENCES repos (id) ON DELETE SET NULL NOT DEFERRABLE',
            'ALTER TABLE pull_request_comments ADD CONSTRAINT FK_3763FD6E4CE0BF7E FOREIGN KEY (pull_request_id) REFERENCES pull_requests (id) ON DELETE CASCADE NOT DEFERRABLE',
            'ALTER TABLE pull_request_comments ADD CONSTRAINT FK_3763FD6EF675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE',
            'ALTER TABLE pull_requests ADD CONSTRAINT FK_2B30AB7C50FA8329 FOREIGN KEY (merged_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE',
            'ALTER TABLE pull_requests ADD CONSTRAINT FK_2B30AB7CBD359B2D FOREIGN KEY (repo_id) REFERENCES repos (id) ON DELETE CASCADE NOT DEFERRABLE',
            'ALTER TABLE pull_requests ADD CONSTRAINT FK_2B30AB7CE7FDFC3C FOREIGN KEY (head_repo_id) REFERENCES repos (id) ON DELETE CASCADE NOT DEFERRABLE',
            'ALTER TABLE pull_requests ADD CONSTRAINT FK_2B30AB7CF675F31B FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE',
            'ALTER TABLE webhook_deliveries ADD CONSTRAINT FK_3681F32D5C9BA60B FOREIGN KEY (webhook_id) REFERENCES webhooks (id) ON DELETE CASCADE NOT DEFERRABLE',
            'ALTER TABLE webhooks ADD CONSTRAINT FK_998C4FDDBD359B2D FOREIGN KEY (repo_id) REFERENCES repos (id) ON DELETE CASCADE NOT DEFERRABLE',
            'ALTER TABLE lfs_locks ADD CONSTRAINT FK_5091253CBD359B2D FOREIGN KEY (repo_id) REFERENCES repos (id) ON DELETE CASCADE NOT DEFERRABLE',
            'ALTER TABLE lfs_locks ADD CONSTRAINT FK_5091253C7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE',
            'ALTER TABLE lfs_objects ADD CONSTRAINT FK_87EECCD3BD359B2D FOREIGN KEY (repo_id) REFERENCES repos (id) ON DELETE CASCADE NOT DEFERRABLE',
    ];

    private const TABLES_IN_DROP_ORDER = [
            'webhook_deliveries',
            'webhooks',
            'lfs_locks',
            'lfs_objects',
            'pull_request_comments',
            'pull_requests',
            'repo_collaborators',
            'user_access_tokens',
            'user_public_keys',
            'repos',
            'users',
            'server_settings',
            'messenger_messages',
    ];

    public function getDescription(): string
    {
        return 'Initial schema: identity, access, repos, pull requests, webhooks, LFS, messenger';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $statements = match (true) {
            $platform instanceof SQLitePlatform => self::SQLITE,
            $platform instanceof PostgreSQLPlatform => self::POSTGRESQL,
            default => null,
        };
        $this->abortIf(null === $statements, 'Only SQLite and PostgreSQL are supported.');

        foreach ($statements as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES_IN_DROP_ORDER as $table) {
            $this->addSql('DROP TABLE '.$table);
        }
    }
}
