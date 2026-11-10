<?php

declare(strict_types=1);

if ('cli-server' === \PHP_SAPI) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
    $publicRoot = realpath(__DIR__);
    $requestedFile = is_string($requestPath) ? realpath(__DIR__.rawurldecode($requestPath)) : false;

    if (
        false !== $publicRoot
        && false !== $requestedFile
        && is_file($requestedFile)
        && str_starts_with($requestedFile, $publicRoot.\DIRECTORY_SEPARATOR)
        && 1 !== preg_match('/\.(?:php\d*|phtml|phar|inc)$/i', $requestedFile)
    ) {
        return false;
    }
}

$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/index.php';
require __DIR__.'/index.php';
