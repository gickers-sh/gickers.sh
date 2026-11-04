<?php

declare(strict_types=1);

namespace App\Webhook\Domain;

enum ContentType: string
{
    case Json = 'json';
    case Form = 'form';

    public function mimeType(): string
    {
        return match ($this) {
            self::Json => 'application/json',
            self::Form => 'application/x-www-form-urlencoded',
        };
    }
}
