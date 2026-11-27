<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Restore global uniqueness on user_public_keys.fingerprint:
 * deduplicates any shared fingerprints (preferring admin accounts, then oldest id),
 * drops UNIQ_USER_FINGERPRINT, and recreates UNIQ_66C8CFA8FC0B754A on (fingerprint).
 */
final class Version20261128000000 extends AbstractMigration
{
    private const DEDUPLICATE_SQL = <<<'SQL'
        DELETE FROM user_public_keys
        WHERE id NOT IN (
            SELECT keep_id FROM (
                SELECT pk.id AS keep_id
                FROM user_public_keys pk
                WHERE pk.id = (
                    SELECT pk2.id
                    FROM user_public_keys pk2
                    INNER JOIN users u2 ON u2.id = pk2.user_id
                    WHERE pk2.fingerprint = pk.fingerprint
                    ORDER BY u2.is_admin DESC, pk2.id ASC
                    LIMIT 1
                )
            ) AS kept
        )
        SQL;

    private const SQLITE = [
        self::DEDUPLICATE_SQL,
        'DROP INDEX IF EXISTS UNIQ_USER_FINGERPRINT',
        'CREATE UNIQUE INDEX UNIQ_66C8CFA8FC0B754A ON user_public_keys (fingerprint)',
    ];

    private const POSTGRESQL = [
        self::DEDUPLICATE_SQL,
        'DROP INDEX IF EXISTS uniq_user_fingerprint',
        'CREATE UNIQUE INDEX UNIQ_66C8CFA8FC0B754A ON user_public_keys (fingerprint)',
    ];

    public function getDescription(): string
    {
        return 'Restore global unique fingerprint constraint on user_public_keys';
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
        $this->addSql('DROP INDEX IF EXISTS UNIQ_66C8CFA8FC0B754A');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_FINGERPRINT ON user_public_keys (user_id, fingerprint)');
    }
}
