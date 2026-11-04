<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// Fresh schema and data directory for the test environment, built once per run.
if ('test' === ($_SERVER['APP_ENV'] ?? null)) {
    $projectDir = dirname(__DIR__);
    (new Filesystem())->remove([$projectDir.'/var/test.db', $projectDir.'/var/test.db-wal', $projectDir.'/var/test.db-shm', $projectDir.'/var/test-data']);
    passthru(sprintf('%s %s/bin/console doctrine:migrations:migrate --no-interaction --env=test --quiet', escapeshellarg(\PHP_BINARY), escapeshellarg($projectDir)), $status);
    if (0 !== $status) {
        throw new RuntimeException('Unable to migrate the test database.');
    }
}
