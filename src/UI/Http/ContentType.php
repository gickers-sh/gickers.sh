<?php

declare(strict_types=1);

namespace App\UI\Http;

/**
 * Minimal filename -> Content-Type mapping for serving raw repository blobs.
 * Falls back to application/octet-stream for unknown extensions.
 */
final class ContentType
{
    private const MAP = [
        'txt' => 'text/plain; charset=utf-8',
        'md' => 'text/markdown; charset=utf-8',
        'markdown' => 'text/markdown; charset=utf-8',
        'rst' => 'text/plain; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'yaml' => 'application/yaml',
        'yml' => 'application/yaml',
        'csv' => 'text/csv; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'pdf' => 'application/pdf',
        'wasm' => 'application/wasm',
        'php' => 'text/plain; charset=utf-8',
        'sh' => 'text/plain; charset=utf-8',
        'go' => 'text/plain; charset=utf-8',
    ];

    public static function fromFilename(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));

        return self::MAP[$ext] ?? 'application/octet-stream';
    }
}
