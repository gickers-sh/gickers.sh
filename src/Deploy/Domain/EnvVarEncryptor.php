<?php

declare(strict_types=1);

namespace App\Deploy\Domain;

interface EnvVarEncryptor
{
    public function encrypt(string $plaintext): string;

    public function decrypt(string $ciphertext): string;
}
