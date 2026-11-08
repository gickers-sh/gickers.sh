<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes the pull request schema and repos.next_pull_request_number.
 * Supports SQLite (3.35+ for DROP COLUMN) and PostgreSQL.
 */
final class Version20261108000000 extends AbstractMigration
{
    private const TABLES_IN_DROP_ORDER = [
        'pull_request_comments',
        'pull_requests',
    ];

    public function getDescription(): string
    {
        return 'Drop pull request tables and repos.next_pull_request_number';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof SQLitePlatform && !$platform instanceof PostgreSQLPlatform,
            'Only SQLite and PostgreSQL are supported.',
        );

        foreach (self::TABLES_IN_DROP_ORDER as $table) {
            $this->addSql('DROP TABLE IF EXISTS ' . $table);
        }
        $this->addSql('ALTER TABLE repos DROP COLUMN next_pull_request_number');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removed pull request data cannot be restored.');
    }
}
