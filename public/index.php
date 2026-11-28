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

// Precedence hierarchy (least to most priority):
// 1. .env (default baseline example)
// 2. .env.local (custom symfony dev overrides)
// 3. .env.docker (custom docker deployment overrides)
if (!is_file($projectDir.'/.env')) {
    $_SERVER['APP_RUNTIME_OPTIONS']['disable_dotenv'] = true;
}

if (('test' !== ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null)) && is_file($projectDir.'/.env.docker')) {
    $_SERVER['APP_RUNTIME_OPTIONS']['dotenv_overload'] = true;
    $_SERVER['APP_RUNTIME_OPTIONS']['dotenv_extra_paths'] ??= [];
    if (!in_array('.env.docker', $_SERVER['APP_RUNTIME_OPTIONS']['dotenv_extra_paths'], true)) {
        $_SERVER['APP_RUNTIME_OPTIONS']['dotenv_extra_paths'][] = '.env.docker';
    }
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static fn (array $context): Kernel => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
