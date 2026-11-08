<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Tui\Format;

use App\UI\Tui\Format\Rows;
use PHPUnit\Framework\TestCase;

final class RowsTest extends TestCase
{
    public function testFileRowColumnAlignmentForVariousSizes(): void
    {
        $rows = [
            Rows::file('-rw-r--r--', '3.6 KB', 'AbstractPage.php', false, false, 80)[0],
            Rows::file('-rw-r--r--', '873 B', 'Page.php', false, false, 80)[0],
            Rows::file('drwxrwxrwx', '', 'Repo', true, false, 80)[0],
            Rows::file('-rw-r--r--', '14.6 KB', 'SelectionPage.php', false, false, 80)[0],
            Rows::file('-rw-r--r--', '102.5 MB', 'LargeFile.bin', false, false, 80)[0],
        ];

        // Strip ANSI escape codes to check raw column alignment
        $plainRows = array_map(static fn (string $row): string => (string) preg_replace('/\e\[[0-9;]*m/', '', $row), $rows);

        // All file/dir names should start at the exact same column index
        $expectedNames = [
            'AbstractPage.php',
            'Page.php',
            'Repo',
            'SelectionPage.php',
            'LargeFile.bin',
        ];

        $namePositions = [];
        foreach ($plainRows as $idx => $line) {
            $name = $expectedNames[$idx];
            $pos = strpos($line, $name);
            self::assertNotFalse($pos, "Expected {$name} in line: {$line}");
            $namePositions[] = $pos;
        }

        self::assertCount(1, array_unique($namePositions), 'All file names must align at the exact same column');
        self::assertSame(1, \count(array_unique($namePositions)));
    }
}
