<?php

declare(strict_types=1);

namespace App\UI\Console\Command\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Copies every data row from a legacy SQLite database into the current (PostgreSQL)
 * connection. The schema must already exist on the target (run migrations first).
 *
 * Both databases share the same Doctrine-generated schema, so the copy is a
 * straight table-by-table transfer. Foreign-key triggers are disabled during the
 * copy (session_replication_role = replica) and identity sequences are reset
 * afterwards. Transient tables (migration log, message queue) are skipped.
 */
#[AsCommand(
    name: 'soft:admin:db:import-sqlite',
    description: 'Copy data from a legacy SQLite database into the current PostgreSQL database',
)]
final class ImportSqliteCommand extends Command
{
    /** Tables that must not be copied: the target owns them already or they are transient. */
    private const SKIP_TABLES = ['doctrine_migration_versions', 'messenger_messages'];

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::OPTIONAL, 'Path to the SQLite .db file', null)
            ->addOption('truncate', null, InputOption::VALUE_NONE, 'Empty each target table before copying (idempotent re-import)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $defaultSqlite = is_file($this->projectDir . '/.data/gickers-sh.db')
            ? $this->projectDir . '/.data/gickers-sh.db'
            : (is_file($this->projectDir . '/.data/codes-sh.db') ? $this->projectDir . '/.data/codes-sh.db' : $this->projectDir . '/.data/gickers-sh.db');
        $source = $input->getArgument('source') ?? $defaultSqlite;
        if (!is_file($source)) {
            $io->error(sprintf('SQLite file not found: %s', $source));

            return Command::FAILURE;
        }

        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $io->error('The target connection is not PostgreSQL. Point DATABASE_URL at Postgres before importing.');

            return Command::FAILURE;
        }

        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $source]);

        $tables = array_values(array_filter(
            $sqlite->createSchemaManager()->listTableNames(),
            static fn(string $table): bool => !in_array($table, self::SKIP_TABLES, true),
        ));

        if ([] === $tables) {
            $io->warning('No tables to import.');

            return Command::SUCCESS;
        }

        $io->section(sprintf('Importing %d table(s) from %s', count($tables), $source));

        $this->connection->beginTransaction();
        try {
            // Disable FK triggers so table order does not matter during the bulk copy.
            $this->connection->executeStatement("SET session_replication_role = 'replica'");

            if ($input->getOption('truncate')) {
                foreach (array_reverse($tables) as $table) {
                    $this->connection->executeStatement('TRUNCATE TABLE ' . $this->quote($table) . ' RESTART IDENTITY CASCADE');
                }
            }

            foreach ($tables as $table) {
                $count = $this->copyTable($sqlite, $table);
                $io->writeln(sprintf('  %-28s %6d rows', $table, $count));
            }

            $this->connection->executeStatement("SET session_replication_role = 'origin'");
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $io->error(sprintf('Import failed, rolled back: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $this->resetSequences($tables);
        $io->success('Import complete.');

        return Command::SUCCESS;
    }

    private function copyTable(Connection $sqlite, string $table): int
    {
        // Target column types drive correct conversion (SQLite 0/1 -> Postgres boolean,
        // SQLite datetime strings -> Postgres timestamps, etc.).
        $types = [];
        foreach ($this->connection->createSchemaManager()->introspectTable($table)->getColumns() as $column) {
            $types[$column->getName()] = $column->getType();
        }

        $platform = $this->connection->getDatabasePlatform();
        $count = 0;
        $rows = $sqlite->executeQuery('SELECT * FROM ' . $this->quote($table))->iterateAssociative();
        foreach ($rows as $row) {
            $data = [];
            $rowTypes = [];
            foreach ($row as $column => $raw) {
                if (!isset($types[$column])) {
                    continue;
                }
                $type = $types[$column];
                // Normalize the raw SQLite scalar to its PHP value first; insert() then
                // serializes it back to the target platform's representation.
                $data[$column] = $type->convertToPHPValue($raw, $platform);
                $rowTypes[$column] = $type;
            }
            $this->connection->insert($table, $data, $rowTypes);
            ++$count;
        }

        return $count;
    }

    /**
     * @param list<string> $tables
     */
    private function resetSequences(array $tables): void
    {
        foreach ($tables as $table) {
            $autoColumn = null;
            foreach ($this->connection->createSchemaManager()->introspectTable($table)->getColumns() as $column) {
                // Only integer identity/serial columns own a sequence; skip UUID or plain PKs.
                if ($column->getAutoincrement()) {
                    $autoColumn = $column->getName();
                    break;
                }
            }
            if (null === $autoColumn) {
                continue;
            }

            $this->connection->executeStatement(
                sprintf(
                    'SELECT setval(pg_get_serial_sequence(?, ?), GREATEST((SELECT COALESCE(MAX(%s), 0) FROM %s), 1))',
                    $this->quote($autoColumn),
                    $this->quote($table),
                ),
                [$table, $autoColumn],
            );
        }
    }

    private function quote(string $identifier): string
    {
        return $this->connection->getDatabasePlatform()->quoteSingleIdentifier($identifier);
    }
}
