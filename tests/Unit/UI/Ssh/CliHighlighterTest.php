<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Ssh;

use App\UI\Ssh\Help\CliHighlighter;
use App\UI\Ssh\Help\CommandCatalog;
use App\UI\Ssh\Help\CommandSpec;
use App\UI\Tui\Render\Theme;
use PHPUnit\Framework\TestCase;

final class CliHighlighterTest extends TestCase
{
    public function testHighlightsComments(): void
    {
        $line = '  # Create a public repository';
        $highlighted = CliHighlighter::highlightLine($line);

        self::assertStringContainsString("\e[3m", $highlighted, 'Comment should be italicized');
        self::assertStringContainsString(Theme::fg(Theme::MUTED), $highlighted);
        self::assertSame($line, (string) preg_replace('/\e\[[0-9;]*m/', '', $highlighted));
    }

    public function testHighlightsBinaries(): void
    {
        $highlighted = CliHighlighter::highlightLine('git clone ssh -p 2223 localhost/my-repo.git');

        self::assertStringContainsString(Theme::fg(Theme::BLUE), $highlighted);
        self::assertStringContainsString("\e[1mgit\e[0m", $highlighted);
        self::assertStringContainsString("\e[1mssh\e[0m", $highlighted);
    }

    public function testHighlightsSubcommands(): void
    {
        $highlighted = CliHighlighter::highlightLine('ssh -p 2223 localhost repo branch protect my-project main');

        self::assertStringContainsString(Theme::fg(Theme::CYAN), $highlighted);
        self::assertStringContainsString("\e[1mrepo\e[0m", $highlighted);
        self::assertStringContainsString("\e[1mbranch\e[0m", $highlighted);
        self::assertStringContainsString("\e[1mprotect\e[0m", $highlighted);
    }

    public function testHighlightsFlags(): void
    {
        $highlighted = CliHighlighter::highlightLine('ssh repo create my-app --private -d "Core backend service"');

        self::assertStringContainsString(Theme::fg(Theme::ORANGE), $highlighted);
        self::assertStringContainsString("\e[1m--private\e[0m", $highlighted);
        self::assertStringContainsString("\e[1m-d\e[0m", $highlighted);
    }

    public function testHighlightsStrings(): void
    {
        $highlighted = CliHighlighter::highlightLine('ssh repo create backend-api -d "Internal microservice"');

        self::assertStringContainsString(Theme::fg(Theme::GREEN), $highlighted);
        self::assertStringContainsString('"Internal microservice"', $highlighted);
    }

    public function testHighlightsUrls(): void
    {
        $highlighted = CliHighlighter::highlightLine('ssh repo webhook create my-app https://ci.example.com/webhook');

        self::assertStringContainsString(Theme::fg(Theme::BLUE), $highlighted);
        self::assertStringContainsString("\e[4mhttps://ci.example.com/webhook\e[0m", $highlighted);
    }

    public function testHighlightsNumbersAndDurations(): void
    {
        $highlighted = CliHighlighter::highlightLine('ssh token create "ci-pipeline" --expires-in 30d');

        self::assertStringContainsString(Theme::fg(Theme::ORANGE), $highlighted);
        self::assertStringContainsString('30d', $highlighted);
    }

    public function testHighlightsBooleans(): void
    {
        $highlighted = CliHighlighter::highlightLine('ssh settings allow-signup true');

        self::assertStringContainsString(Theme::fg(Theme::HIGHLIGHT), $highlighted);
        self::assertStringContainsString("\e[1mtrue\e[0m", $highlighted);
    }

    public function testHighlightsGitRefsAndSHAs(): void
    {
        $highlighted = CliHighlighter::highlightLine('ssh repo commit my-app 8a3f12c');

        self::assertStringContainsString(Theme::fg(Theme::HASH), $highlighted);
        self::assertStringContainsString("\e[1m8a3f12c\e[0m", $highlighted);
    }

    public function testHighlightsShellRedirections(): void
    {
        $highlighted = CliHighlighter::highlightLine('ssh repo blob my-app main composer.json -r > composer.json');

        self::assertStringContainsString(Theme::fg(Theme::HASH), $highlighted);
        self::assertStringContainsString("\e[1m>\e[0m", $highlighted);
    }

    public function testPreservesLayoutForEveryCatalogExample(): void
    {
        $catalog = new CommandCatalog();
        $root = $catalog->root(true);

        $examples = [];
        $collect = static function (CommandSpec $node, array &$examples, callable $collect): void {
            if ('' !== $node->example) {
                $examples[] = $node->example;
            }
            foreach ($node->children as $child) {
                $collect($child, $examples, $collect);
            }
        };
        $collect($root, $examples, $collect);

        self::assertNotEmpty($examples);

        $lineCount = 0;
        /** @var list<string> $examplesList */
        $examplesList = $examples;
        foreach ($examplesList as $example) {
            $substituted = str_replace('{ssh}', 'ssh -p 2223 localhost', $example);
            foreach (explode("\n", $substituted) as $line) {
                ++$lineCount;
                $highlighted = CliHighlighter::highlightLine($line);
                $stripped = (string) preg_replace('/\e\[[0-9;]*m/', '', $highlighted);
                self::assertSame($line, $stripped, sprintf('Line "%s" failed stripped match', $line));
            }
        }

        self::assertGreaterThan(200, $lineCount);
    }
}
