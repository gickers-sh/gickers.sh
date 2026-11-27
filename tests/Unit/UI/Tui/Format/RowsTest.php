<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Tui\Format;

use App\UI\Tui\Format\Chrome;
use App\UI\Tui\Format\Rows;
use PHPUnit\Framework\TestCase;

final class RowsTest extends TestCase
{
    protected function setUp(): void
    {
        Rows::setDefaultRenderer(null);
        Chrome::setDefaultRenderer(null);
    }
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

    public function testDeploymentRowRendersStatusShaAndMetadata(): void
    {
        $rows = Rows::deployment(
            'main',
            'branch',
            'running',
            true,
            '04a54be',
            [
                'Domains: https://prod.gickers.sh -> web:8080',
                'Uptime: 2h 15m · Quota: 1 CPU, 512MB RAM, 128 PIDs, max 4 services · Env vars: 1 · Network: gickers_env_1',
                'Latest release: #2 [succeeded] by @alice · 2h ago (build 12s) · 2 recent runs',
            ],
            true,
            120,
            '2h 15m',
        );

        self::assertCount(4, $rows);
        $plain0 = (string) preg_replace('/\e\[[0-9;]*m/', '', $rows[0]);
        $plain1 = (string) preg_replace('/\e\[[0-9;]*m/', '', $rows[1]);
        $plain2 = (string) preg_replace('/\e\[[0-9;]*m/', '', $rows[2]);
        $plain3 = (string) preg_replace('/\e\[[0-9;]*m/', '', $rows[3]);

        self::assertStringContainsString('main (branch)', $plain0);
        self::assertStringContainsString('[running]', $plain0);
        self::assertStringContainsString('up 2h 15m', $plain0);
        self::assertStringContainsString('04a54be', $plain0);
        self::assertStringContainsString('https://prod.gickers.sh -> web:8080', $plain1);
        self::assertStringContainsString('Uptime: 2h 15m · Quota: 1 CPU, 512MB RAM, 128 PIDs', $plain2);
        self::assertStringContainsString('Latest release: #2 [succeeded] by @alice', $plain3);
    }

    public function testReleaseRowRendersIdStatusShaTriggerAndDuration(): void
    {
        $rows = Rows::release(
            12,
            true,
            'succeeded',
            '04a54be',
            '@alice',
            '2026-11-22 18:20:00 (1m ago)',
            '14s',
            true,
            100,
            'main',
            '04a54bef59c494670dac37af76384308cf2552b3',
            '==> Deployment succeeded.',
            '45m 10s',
        );

        self::assertCount(4, $rows);
        $plain0 = (string) preg_replace('/\e\[[0-9;]*m/', '', $rows[0]);
        $plain1 = (string) preg_replace('/\e\[[0-9;]*m/', '', $rows[1]);
        $plain2 = (string) preg_replace('/\e\[[0-9;]*m/', '', $rows[2]);
        $plain3 = (string) preg_replace('/\e\[[0-9;]*m/', '', $rows[3]);
        self::assertStringContainsString('#12 (main · latest)', $plain0);
        self::assertStringContainsString('[succeeded]', $plain0);
        self::assertStringContainsString('up 45m 10s', $plain0);
        self::assertStringContainsString('04a54be', $plain0);
        self::assertStringContainsString('Commit: 04a54bef59c494670dac37af76384308cf2552b3 · Triggered by: @alice', $plain1);
        self::assertStringContainsString('Started: 2026-11-22 18:20:00 (1m ago) · Build duration: 14s · Uptime: 45m 10s', $plain2);
        self::assertStringContainsString('Output: ==> Deployment succeeded.', $plain3);
    }

    public function testResponsiveWordWrapOnNarrowTerminalsNeverTruncatesInfo(): void
    {
        // Narrow terminal width (68 cols) matching screenshot scenario
        $headerLines = Chrome::repoHeader(
            'os.directory/os.directory',
            'A long description that wraps onto a second line cleanly when the terminal is narrow.',
            'git clone ssh://gickers.sh/os.directory/os.directory.git',
            68,
        );
        $plainHeader = implode("\n", array_map(static fn (string $r): string => (string) preg_replace('/\e\[[0-9;]*m/', '', $r), $headerLines));
        self::assertStringContainsString('os.directory/os.directory', $plainHeader);
        self::assertStringContainsString('git clone ssh://gickers.sh/os.directory/os.directory.git', $plainHeader);
        self::assertStringContainsString('second line cleanly', $plainHeader);

        $deployRows = Rows::deployment(
            'main',
            'branch',
            'running',
            true,
            '306291f',
            [
                'Domains: https://mail.os.gickers.sh -> mailer:1080, https://os.gickers.sh -> nginx:80',
                'Uptime: 3m 9s · Quota: 1 CPU, 512MB RAM, 128 PIDs, max 4 services · Env vars: 2 · Network: gickers_env_1',
                'Latest release: #17 [succeeded] by git push · 3m ago (build 16s) · 5 recent runs',
            ],
            true,
            68,
            '3m 9s',
        );
        $plainDeploy = implode("\n", array_map(static fn (string $r): string => (string) preg_replace('/\e\[[0-9;]*m/', '', $r), $deployRows));
        self::assertGreaterThan(4, \count($deployRows));
        self::assertStringContainsString('https://os.gickers.sh -> nginx:80', $plainDeploy);
        self::assertStringContainsString('Network: gickers_env_1', $plainDeploy);
        self::assertStringContainsString('5 recent runs', $plainDeploy);
    }
}
