<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Tui;

use App\UI\Tui\Render\Screen;
use PHPUnit\Framework\TestCase;

final class ScreenFileRowTest extends TestCase
{
    public function testFileRowColumnAlignmentForVariousSizes(): void
    {
        $rows = [
            Screen::fileRow('-rw-r--r--', '3.6 KB', 'AbstractPage.php', false, false, 80)[0],
            Screen::fileRow('-rw-r--r--', '873 B', 'Page.php', false, false, 80)[0],
            Screen::fileRow('drwxrwxrwx', '', 'Repo', true, false, 80)[0],
            Screen::fileRow('-rw-r--r--', '14.6 KB', 'SelectionPage.php', false, false, 80)[0],
            Screen::fileRow('-rw-r--r--', '102.5 MB', 'LargeFile.bin', false, false, 80)[0],
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
