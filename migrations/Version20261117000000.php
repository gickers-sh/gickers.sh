<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Allow a public key fingerprint to be shared across multiple accounts:
 * replaces global unique index on fingerprint with compound unique (user_id, fingerprint).
 */
final class Version20261117000000 extends AbstractMigration
{
    private const SQLITE = [
        'DROP INDEX UNIQ_66C8CFA8FC0B754A',
        'CREATE UNIQUE INDEX UNIQ_USER_FINGERPRINT ON user_public_keys (user_id, fingerprint)',
    ];

    private const POSTGRESQL = [
        'DROP INDEX UNIQ_66C8CFA8FC0B754A',
        'CREATE UNIQUE INDEX UNIQ_USER_FINGERPRINT ON user_public_keys (user_id, fingerprint)',
    ];

    public function getDescription(): string
    {
        return 'Replace global unique fingerprint with unique (user_id, fingerprint) on user_public_keys';
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
        $this->addSql('DROP INDEX UNIQ_USER_FINGERPRINT');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_66C8CFA8FC0B754A ON user_public_keys (fingerprint)');
    }
}
