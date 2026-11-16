<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Web-session revocation counter: each successful `web login` cookie embeds the
 * current web_token_version; `web logout` bumps it, invalidating every outstanding
 * session cookie. Supports SQLite and PostgreSQL.
 */
final class Version20261115000000 extends AbstractMigration
{
    private const SQLITE = [
        'ALTER TABLE users ADD COLUMN web_token_version INTEGER NOT NULL DEFAULT 0',
    ];

    private const POSTGRESQL = [
        'ALTER TABLE users ADD web_token_version INT DEFAULT 0 NOT NULL',
    ];

    public function getDescription(): string
    {
        return 'Web-session revocation counter (web_token_version) on users';
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
        $this->addSql('ALTER TABLE users DROP COLUMN web_token_version');
    }
}
