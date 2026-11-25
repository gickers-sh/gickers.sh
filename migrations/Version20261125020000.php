<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add single_use and used_at columns to user_access_tokens table.
 */
final class Version20261125020000 extends AbstractMigration
{
    private const SQLITE = [
        'ALTER TABLE user_access_tokens ADD COLUMN single_use BOOLEAN NOT NULL DEFAULT 0',
        'ALTER TABLE user_access_tokens ADD COLUMN used_at DATETIME DEFAULT NULL',
    ];

    private const POSTGRESQL = [
        'ALTER TABLE user_access_tokens ADD single_use BOOLEAN DEFAULT FALSE NOT NULL',
        'ALTER TABLE user_access_tokens ADD used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
    ];

    public function getDescription(): string
    {
        return 'Add single_use and used_at columns to user_access_tokens table';
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
        $this->addSql('ALTER TABLE user_access_tokens DROP COLUMN single_use');
        $this->addSql('ALTER TABLE user_access_tokens DROP COLUMN used_at');
    }
}
