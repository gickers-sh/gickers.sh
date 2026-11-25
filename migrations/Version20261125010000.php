<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add user_web_login_tickets table and scopes/repos/description to user_access_tokens.
 */
final class Version20261125010000 extends AbstractMigration
{
    private const SQLITE = [
        'CREATE TABLE user_web_login_tickets (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, CONSTRAINT FK_user_web_login_tickets FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE)',
        'CREATE UNIQUE INDEX UNIQ_user_web_login_tickets_token_hash ON user_web_login_tickets (token_hash)',
        'ALTER TABLE user_access_tokens ADD COLUMN scopes CLOB DEFAULT NULL',
        'ALTER TABLE user_access_tokens ADD COLUMN repos CLOB DEFAULT NULL',
        'ALTER TABLE user_access_tokens ADD COLUMN description VARCHAR(255) DEFAULT NULL',
    ];

    private const POSTGRESQL = [
        'CREATE TABLE user_web_login_tickets (id SERIAL NOT NULL, user_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT FK_user_web_login_tickets FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
        'CREATE UNIQUE INDEX UNIQ_user_web_login_tickets_token_hash ON user_web_login_tickets (token_hash)',
        'ALTER TABLE user_access_tokens ADD scopes JSON DEFAULT NULL',
        'ALTER TABLE user_access_tokens ADD repos JSON DEFAULT NULL',
        'ALTER TABLE user_access_tokens ADD description VARCHAR(255) DEFAULT NULL',
    ];

    public function getDescription(): string
    {
        return 'Add web login tickets table and scopes/repos/description to access tokens';
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
        $this->addSql('DROP TABLE user_web_login_tickets');
        $this->addSql('ALTER TABLE user_access_tokens DROP COLUMN scopes');
        $this->addSql('ALTER TABLE user_access_tokens DROP COLUMN repos');
        $this->addSql('ALTER TABLE user_access_tokens DROP COLUMN description');
    }
}
