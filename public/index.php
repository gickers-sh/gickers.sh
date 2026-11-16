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

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static fn (array $context): Kernel => new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
