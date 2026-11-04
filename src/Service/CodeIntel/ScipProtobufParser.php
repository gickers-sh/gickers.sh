<?php

declare(strict_types=1);

namespace App\Service\CodeIntel;

use App\Model\CodeIntel\OccurrenceModel;

/**
 * Pure-PHP binary Protobuf wire-format decoder & encoder for SCIP (Source Code Intelligence Protocol).
 * Supports Index (field 3 documents), Document (fields 1 relative_path, 2 occurrences, 4 language),
 * and Occurrence (fields 1 range, 2 symbol, 3 symbol_roles).
 */
final class ScipProtobufParser
{
    public const int SYMBOL_ROLE_DEFINITION = 0x1;

    public const int WIRE_VARINT = 0;
    public const int WIRE_FIXED64 = 1;
    public const int WIRE_LENGTH_DELIMITED = 2;
    public const int WIRE_START_GROUP = 3;
    public const int WIRE_END_GROUP = 4;
    public const int WIRE_FIXED32 = 5;

    /**
     * Decodes a binary SCIP Index into an array of documents and occurrences.
     *
     * @return array{documents: array<int, array{relative_path: string, occurrences: array<int, array{range: int[], symbol: string, symbol_roles: int}>, language: string}>}
     */
    public function decodeIndex(string $binary): array
    {
        $offset = 0;
        $limit = strlen($binary);
        $documents = [];

        while ($offset < $limit) {
            $tag = $this->readVarint($binary, $offset, $limit);
            $fieldNumber = $tag >> 3;
            $wireType = $tag & 0x07;

            if ($fieldNumber === 3 && $wireType === self::WIRE_LENGTH_DELIMITED) {
                // Repeated Document
                $docLen = $this->readVarint($binary, $offset, $limit);
                $docBytes = substr($binary, $offset, $docLen);
                $offset += $docLen;
                $documents[] = $this->decodeDocument($docBytes);
            } else {
                $this->skipField($wireType, $binary, $offset, $limit);
            }
        }

        return [
            'documents' => $documents,
        ];
    }

    /**
     * Serializes an array of documents into a binary SCIP Index.
     *
     * @param array<int, array{relative_path?: string, path?: string, occurrences?: array<int, array{range?: int[], symbol?: string, symbol_roles?: int}|OccurrenceModel>, language?: string}>|array{documents: array} $documents
     */
    public function encodeIndex(array $documents): string
    {
        if (isset($documents['documents']) && is_array($documents['documents'])) {
            $documents = $documents['documents'];
        }

        $bytes = '';
        foreach ($documents as $doc) {
            $docBytes = $this->encodeDocument($doc);
            $bytes .= $this->writeLengthDelimited(3, $docBytes);
        }

        return $bytes;
    }

    /**
     * Decodes a single Document message from bytes.
     *
     * @return array{relative_path: string, occurrences: array<int, array{range: int[], symbol: string, symbol_roles: int}>, language: string}
     */
    public function decodeDocument(string $bytes): array
    {
        $offset = 0;
        $limit = strlen($bytes);
        $relativePath = '';
        $occurrences = [];
        $language = '';

        while ($offset < $limit) {
            $tag = $this->readVarint($bytes, $offset, $limit);
            $fieldNumber = $tag >> 3;
            $wireType = $tag & 0x07;

            if ($fieldNumber === 1 && $wireType === self::WIRE_LENGTH_DELIMITED) {
                // relative_path
                $len = $this->readVarint($bytes, $offset, $limit);
                $relativePath = substr($bytes, $offset, $len);
                $offset += $len;
            } elseif ($fieldNumber === 2 && $wireType === self::WIRE_LENGTH_DELIMITED) {
                // repeated Occurrence
                $occLen = $this->readVarint($bytes, $offset, $limit);
                $occBytes = substr($bytes, $offset, $occLen);
                $offset += $occLen;
                $occurrences[] = $this->decodeOccurrence($occBytes);
            } elseif ($fieldNumber === 4 && $wireType === self::WIRE_LENGTH_DELIMITED) {
                // language
                $len = $this->readVarint($bytes, $offset, $limit);
                $language = substr($bytes, $offset, $len);
                $offset += $len;
            } else {
                $this->skipField($wireType, $bytes, $offset, $limit);
            }
        }

        return [
            'relative_path' => $relativePath,
            'occurrences' => $occurrences,
            'language' => $language,
        ];
    }

    /**
     * Encodes a single Document message into bytes.
     *
     * @param array{relative_path?: string, path?: string, occurrences?: array<int, array{range?: int[], symbol?: string, symbol_roles?: int}|OccurrenceModel>, language?: string} $doc
     */
    public function encodeDocument(array $doc): string
    {
        $bytes = '';
        $relativePath = (string) ($doc['relative_path'] ?? $doc['path'] ?? '');
        $language = (string) ($doc['language'] ?? '');
        $occurrences = (array) ($doc['occurrences'] ?? []);

        // Field 1: relative_path
        if ($relativePath !== '') {
            $bytes .= $this->writeLengthDelimited(1, $relativePath);
        }

        // Field 2: repeated Occurrence
        foreach ($occurrences as $occ) {
            $occBytes = $this->encodeOccurrence($occ);
            $bytes .= $this->writeLengthDelimited(2, $occBytes);
        }

        // Field 4: language
        if ($language !== '') {
            $bytes .= $this->writeLengthDelimited(4, $language);
        }

        return $bytes;
    }

    /**
     * Decodes a single Occurrence message from bytes.
     *
     * @return array{range: int[], symbol: string, symbol_roles: int}
     */
    public function decodeOccurrence(string $bytes): array
    {
        $offset = 0;
        $limit = strlen($bytes);
        $range = [];
        $symbol = '';
        $symbolRoles = 0;

        while ($offset < $limit) {
            $tag = $this->readVarint($bytes, $offset, $limit);
            $fieldNumber = $tag >> 3;
            $wireType = $tag & 0x07;

            if ($fieldNumber === 1) {
                // range (packed or non-packed repeated int32)
                if ($wireType === self::WIRE_LENGTH_DELIMITED) {
                    $len = $this->readVarint($bytes, $offset, $limit);
                    $end = min($limit, $offset + $len);
                    while ($offset < $end) {
                        $range[] = $this->readVarint($bytes, $offset, $end);
                    }
                } elseif ($wireType === self::WIRE_VARINT) {
                    $range[] = $this->readVarint($bytes, $offset, $limit);
                } else {
                    $this->skipField($wireType, $bytes, $offset, $limit);
                }
            } elseif ($fieldNumber === 2 && $wireType === self::WIRE_LENGTH_DELIMITED) {
                // symbol
                $len = $this->readVarint($bytes, $offset, $limit);
                $symbol = substr($bytes, $offset, $len);
                $offset += $len;
            } elseif ($fieldNumber === 3 && $wireType === self::WIRE_VARINT) {
                // symbol_roles
                $symbolRoles = $this->readVarint($bytes, $offset, $limit);
            } else {
                $this->skipField($wireType, $bytes, $offset, $limit);
            }
        }

        return [
            'range' => $range,
            'symbol' => $symbol,
            'symbol_roles' => $symbolRoles,
        ];
    }

    /**
     * Encodes a single Occurrence message into bytes.
     *
     * @param array{range?: int[], symbol?: string, symbol_roles?: int, is_definition?: bool}|OccurrenceModel $occ
     */
    public function encodeOccurrence(array|OccurrenceModel $occ): string
    {
        $bytes = '';

        if ($occ instanceof OccurrenceModel) {
            $range = ($occ->startLine === $occ->endLine)
                ? [$occ->startLine, $occ->startCol, $occ->endCol]
                : [$occ->startLine, $occ->startCol, $occ->endLine, $occ->endCol];
            $symbol = $occ->symbol;
            $roles = $occ->isDefinition ? self::SYMBOL_ROLE_DEFINITION : 0;
        } else {
            $range = $occ['range'] ?? [];
            $symbol = (string) ($occ['symbol'] ?? '');
            $roles = (int) ($occ['symbol_roles'] ?? (!empty($occ['is_definition']) ? self::SYMBOL_ROLE_DEFINITION : 0));
        }

        // Field 1: packed range (repeated int32, length-delimited)
        if (!empty($range)) {
            $packed = '';
            foreach ($range as $num) {
                $packed .= $this->writeVarint((int) $num);
            }
            $bytes .= $this->writeLengthDelimited(1, $packed);
        }

        // Field 2: symbol (string, length-delimited)
        if ($symbol !== '') {
            $bytes .= $this->writeLengthDelimited(2, $symbol);
        }

        // Field 3: symbol_roles (int32 varint)
        if ($roles !== 0) {
            $bytes .= $this->writeTag(3, self::WIRE_VARINT) . $this->writeVarint($roles);
        }

        return $bytes;
    }

    /**
     * Converts a raw SCIP int32 range array into structured start/end coordinates.
     * 3-int: [startLine, startCol, endCol] (single line)
     * 4-int: [startLine, startCol, endLine, endCol] (multiline)
     *
     * @param int[] $range
     * @return array{startLine: int, startCol: int, endLine: int, endCol: int}
     */
    public static function parseRange(array $range): array
    {
        $count = count($range);
        if ($count === 3) {
            return [
                'startLine' => (int) $range[0],
                'startCol' => (int) $range[1],
                'endLine' => (int) $range[0],
                'endCol' => (int) $range[2],
            ];
        }

        if ($count >= 4) {
            return [
                'startLine' => (int) $range[0],
                'startCol' => (int) $range[1],
                'endLine' => (int) $range[2],
                'endCol' => (int) $range[3],
            ];
        }

        return [
            'startLine' => 0,
            'startCol' => 0,
            'endLine' => 0,
            'endCol' => 0,
        ];
    }

    /**
     * Encodes a variable-length integer into Protobuf wire format.
     */
    public function writeVarint(int $value): string
    {
        if ($value === 0) {
            return "\x00";
        }

        if ($value < 0) {
            $buf = '';
            for ($i = 0; $i < 10; ++$i) {
                $byte = $value & 0x7F;
                $value >>= 7;
                $buf .= chr($i < 9 ? ($byte | 0x80) : ($byte & 0x7F));
            }
            return $buf;
        }

        $buf = '';
        while ($value > 0x7F) {
            $buf .= chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }
        $buf .= chr($value & 0x7F);

        return $buf;
    }

    /**
     * Decodes a variable-length integer from bytes.
     */
    public function readVarint(string $bytes, int &$offset, int $limit): int
    {
        $result = 0;
        $shift = 0;

        while ($offset < $limit) {
            $byte = ord($bytes[$offset++]);
            $result |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
            if ($shift >= 64) {
                break;
            }
        }

        return $result;
    }

    /**
     * Writes a field tag (fieldNumber << 3 | wireType).
     */
    public function writeTag(int $fieldNumber, int $wireType): string
    {
        return $this->writeVarint(($fieldNumber << 3) | ($wireType & 0x07));
    }

    /**
     * Writes a length-delimited field header and payload.
     */
    public function writeLengthDelimited(int $fieldNumber, string $data): string
    {
        return $this->writeTag($fieldNumber, self::WIRE_LENGTH_DELIMITED)
            . $this->writeVarint(strlen($data))
            . $data;
    }

    /**
     * Skips over unknown or unhandled Protobuf fields.
     */
    public function skipField(int $wireType, string $bytes, int &$offset, int $limit): void
    {
        switch ($wireType) {
            case self::WIRE_VARINT:
                $this->readVarint($bytes, $offset, $limit);
                break;
            case self::WIRE_FIXED64:
                $offset = min($limit, $offset + 8);
                break;
            case self::WIRE_LENGTH_DELIMITED:
                $len = $this->readVarint($bytes, $offset, $limit);
                $offset = min($limit, $offset + $len);
                break;
            case self::WIRE_FIXED32:
                $offset = min($limit, $offset + 4);
                break;
            default:
                break;
        }
    }
}
