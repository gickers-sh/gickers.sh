<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Git;

/**
 * Git pkt-line codec (`gitprotocol-common(5)`), shared by the Smart HTTP and
 * git:// daemon transports.
 */
final class PktLine
{
    public const FLUSH = '0000';
    public const DELIM = '0001';
    public const RESPONSE_END = '0002';

    /** 0xFFF0 total line length minus the 4-byte length prefix. */
    public const MAX_PAYLOAD = 65516;

    private function __construct()
    {
    }

    /**
     * Encodes a payload into a data pkt-line with its 4-hex length prefix.
     *
     * @throws \InvalidArgumentException when the payload exceeds {@see MAX_PAYLOAD}
     */
    public static function encode(string $payload): string
    {
        $length = \strlen($payload) + 4;
        if ($length > 65520) {
            throw new \InvalidArgumentException(sprintf('Pkt-line payload too large (%d bytes, maximum is %d bytes).', \strlen($payload), self::MAX_PAYLOAD));
        }

        return sprintf('%04x%s', $length, $payload);
    }

    /** Returns the flush packet ("0000"). */
    public static function flush(): string
    {
        return self::FLUSH;
    }

    /**
     * Parses the first packet at the head of $buffer.
     *
     * @return array{type: 'data'|'flush'|'delim'|'response-end', payload: string, length: int}
     *                                                                                          $length is the number of bytes consumed from $buffer (always 4 for control packets)
     *
     * @throws \InvalidArgumentException when the length header is malformed or the data packet is truncated
     */
    public static function parse(string $buffer): array
    {
        if (\strlen($buffer) < 4) {
            throw new \InvalidArgumentException('Incomplete pkt-line: fewer than 4 bytes available.');
        }

        $header = substr($buffer, 0, 4);
        if (!ctype_xdigit($header)) {
            throw new \InvalidArgumentException(sprintf('Invalid pkt-line length header "%s".', $header));
        }

        $declared = (int) hexdec($header);

        switch ($declared) {
            case 0:
                return ['type' => 'flush', 'payload' => '', 'length' => 4];
            case 1:
                return ['type' => 'delim', 'payload' => '', 'length' => 4];
            case 2:
                return ['type' => 'response-end', 'payload' => '', 'length' => 4];
            case 3:
                throw new \InvalidArgumentException('Invalid pkt-line length "0003".');
        }

        if (\strlen($buffer) < $declared) {
            throw new \InvalidArgumentException(sprintf('Truncated pkt-line: declared %d bytes, %d available.', $declared, \strlen($buffer)));
        }

        return [
            'type' => 'data',
            'payload' => substr($buffer, 4, $declared - 4),
            'length' => $declared,
        ];
    }
}
