<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Enables FK enforcement, a busy timeout and WAL on every SQLite connection:
 * the web worker, git hooks and SSH sessions write concurrently.
 */
#[AsMiddleware]
final class SqlitePragmaMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);
                if (\in_array($params['driver'] ?? null, ['pdo_sqlite', 'sqlite3'], true)) {
                    $connection->exec('PRAGMA foreign_keys = ON');
                    $connection->exec('PRAGMA busy_timeout = 5000');
                    if (!($params['memory'] ?? false)) {
                        $connection->exec('PRAGMA journal_mode = WAL');
                    }
                }

                return $connection;
            }
        };
    }
}
