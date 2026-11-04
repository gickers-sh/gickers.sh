<?php

declare(strict_types=1);

namespace App\Service\Ssh;

/**
 * Parses and compares OpenSSH public keys according to RFC 4253 / OpenSSH authorized_keys format.
 * Provides constant-time key comparison and SHA-256 fingerprint calculation matching Go's gossh.FingerprintSHA256.
 */
final class SshKeyComparator
{
    /**
     * Parse an OpenSSH authorized_keys line into structured parts:
     * - options (optional string or null)
     * - type (e.g. ssh-ed25519, ssh-rsa, ecdsa-sha2-nistp256)
     * - key (the base64-encoded wire key)
     * - comment (optional string or null)
     * - wire (binary decoded bytes)
     *
     * @return array{
     *     options: ?string,
     *     type: string,
     *     key: string,
     *     comment: ?string,
     *     wire: string
     * }|null
     */
    public function parse(string $authorizedKeyLine): ?array
    {
        $line = trim($authorizedKeyLine);
        if ('' === $line || str_starts_with($line, '#')) {
            return null;
        }

        // Tokenize line respecting quoted strings in options
        $tokens = $this->tokenizeKeyLine($line);
        if (0 === count($tokens)) {
            return null;
        }

        $options = null;
        $type = null;
        $keyBody = null;
        $comment = null;

        // Check if first token is a known key type
        if ($this->isKeyType($tokens[0])) {
            $type = $tokens[0];
            $keyBody = $tokens[1] ?? null;
            $comment = isset($tokens[2]) ? implode(' ', array_slice($tokens, 2)) : null;
        } elseif (count($tokens) >= 2 && $this->isKeyType($tokens[1])) {
            $options = $tokens[0];
            $type = $tokens[1];
            $keyBody = $tokens[2] ?? null;
            $comment = isset($tokens[3]) ? implode(' ', array_slice($tokens, 3)) : null;
        } elseif (1 === count($tokens) && $this->isValidBase64($tokens[0])) {
            // Bare base64 key
            $keyBody = $tokens[0];
            $type = 'unknown';
        }

        if (null === $keyBody || '' === $keyBody) {
            return null;
        }

        $wire = base64_decode($keyBody, true);
        if (false === $wire || '' === $wire) {
            return null;
        }

        return [
            'options' => $options,
            'type' => $type ?? 'unknown',
            'key' => $keyBody,
            'comment' => $comment,
            'wire' => $wire,
        ];
    }

    /**
     * Compute OpenSSH SHA256 fingerprint:
     * "SHA256:" . rtrim(base64_encode(hash('sha256', base64_decode($keyBody), true)), '=')
     * Matches gossh.FingerprintSHA256 exactly.
     */
    public function fingerprintSha256(string $authorizedKey): string
    {
        $keyBody = $this->extractKeyBody($authorizedKey);
        $wire = base64_decode($keyBody, true);
        if (false === $wire || '' === $wire) {
            throw new \InvalidArgumentException('Invalid base64 encoded SSH key wire format.');
        }

        $hash = hash('sha256', $wire, true);

        return 'SHA256:'.rtrim(base64_encode($hash), '=');
    }

    /**
     * Constant-time comparison of two SSH public keys, ignoring comments and options.
     * Matches ssh.KeysEqual.
     */
    public function keysEqual(string $keyA, string $keyB): bool
    {
        try {
            $wireA = base64_decode($this->extractKeyBody($keyA), true);
            $wireB = base64_decode($this->extractKeyBody($keyB), true);
        } catch (\InvalidArgumentException) {
            return false;
        }

        if (false === $wireA || false === $wireB) {
            return false;
        }

        return hash_equals($wireA, $wireB);
    }

    /**
     * Extract the raw base64 key body from an authorized_keys entry.
     */
    public function extractKeyBody(string $authorizedKey): string
    {
        $parsed = $this->parse($authorizedKey);
        if (null !== $parsed) {
            return $parsed['key'];
        }

        $trimmed = trim($authorizedKey);
        if ($this->isValidBase64($trimmed)) {
            return $trimmed;
        }

        throw new \InvalidArgumentException(sprintf('Cannot extract SSH key body from: "%s"', $authorizedKey));
    }

    /**
     * Checks if a string looks like a standard OpenSSH key type.
     */
    public function isKeyType(string $str): bool
    {
        return str_starts_with($str, 'ssh-')
            || str_starts_with($str, 'ecdsa-')
            || str_starts_with($str, 'sk-ssh-')
            || str_starts_with($str, 'sk-ecdsa-');
    }

    /**
     * Tokenize a line while preserving quoted substrings in options (e.g. command="...").
     *
     * @return string[]
     */
    private function tokenizeKeyLine(string $line): array
    {
        $tokens = [];
        $len = strlen($line);
        $inQuote = false;
        $quoteChar = '';
        $currentToken = '';

        for ($i = 0; $i < $len; ++$i) {
            $char = $line[$i];

            if ($inQuote) {
                if ($char === $quoteChar) {
                    $inQuote = false;
                    $currentToken .= $char;
                } elseif ('\\' === $char && $i + 1 < $len && $line[$i + 1] === $quoteChar) {
                    $currentToken .= $quoteChar;
                    ++$i;
                } else {
                    $currentToken .= $char;
                }
            } else {
                if ('"' === $char || "'" === $char) {
                    $inQuote = true;
                    $quoteChar = $char;
                    $currentToken .= $char;
                } elseif (' ' === $char || "\t" === $char) {
                    if ('' !== $currentToken) {
                        $tokens[] = $currentToken;
                        $currentToken = '';
                    }
                } else {
                    $currentToken .= $char;
                }
            }
        }

        if ('' !== $currentToken) {
            $tokens[] = $currentToken;
        }

        return $tokens;
    }

    private function isValidBase64(string $str): bool
    {
        $cleaned = trim($str);
        if ('' === $cleaned) {
            return false;
        }

        $decoded = base64_decode($cleaned, true);
        if (false === $decoded) {
            return false;
        }

        return rtrim(base64_encode($decoded), '=') === rtrim($cleaned, '=');
    }
}
