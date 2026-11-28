<?php

declare(strict_types=1);

use App\Kernel;

// FrankenPHP exposes worker-specific variables through the process environment,
// while Symfony Runtime selects its worker runner from $_SERVER. Mirror the flag
// before autoload_runtime.php instantiates the runtime.
$frankenphpWorker = $_SERVER['FRANKENPHP_WORKER'] ?? $_ENV['FRANKENPHP_WORKER'] ?? getenv('FRANKENPHP_WORKER');
if (false !== $frankenphpWorker && null !== $frankenphpWorker && '' !== $frankenphpWorker) {
    $_SERVER['FRANKENPHP_WORKER'] = $frankenphpWorker;
}

$projectDir = dirname(__DIR__);
$_SERVER['APP_RUNTIME_OPTIONS'] ??= $_ENV['APP_RUNTIME_OPTIONS'] ?? [];
if (is_string($_SERVER['APP_RUNTIME_OPTIONS'])) {
    $_SERVER['APP_RUNTIME_OPTIONS'] = json_decode($_SERVER['APP_RUNTIME_OPTIONS'], true, 512, JSON_THROW_ON_ERROR) ?? [];
}

// Disable Dotenv if .env is missing.
// Note: On host/local dev, Symfony loads .env and .env.local.
// In Docker, compose.yaml injects .env.docker via env_file (highest priority).
if (!is_file($projectDir.'/.env')) {
    $_SERVER['APP_RUNTIME_OPTIONS']['disable_dotenv'] = true;
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static fn (array $context): Kernel => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
