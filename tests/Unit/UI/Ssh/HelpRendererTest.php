<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Ssh;

use App\UI\Ssh\Help\CommandCatalog;
use App\UI\Ssh\Help\CommandSpec;
use App\UI\Ssh\Help\HelpRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the cobra-style SSH help output matches the upstream Soft Serve
 * server byte-for-byte. The fixture is captured from `ssh codes.sh help …`
 * with the SSH prefix rewritten to this server's `ssh -p 2223 localhost`.
 *
 * Commands that only exist in codes.sh (`repo signatures`,
 * `repo branch protect|unprotect`, `repo diff`,
 * `repo grep`, `repo readme`) have no upstream capture: their blocks, and their
 * lines in the parent command listings, follow the same format.
 */
final class HelpRendererTest extends TestCase
{
    private const SSH_COMMAND = 'ssh -p 2223 localhost';

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function helpProvider(): iterable
    {
        $raw = (string) file_get_contents(__DIR__ . '/fixtures/cobra-help.txt');

        /** @var array<string, string> $blocks */
        $blocks = [];
        $path = null;
        $buffer = '';
        foreach (explode("\n", $raw) as $line) {
            if (str_starts_with($line, '########## ')) {
                if (null !== $path) {
                    $blocks[$path] = rtrim($buffer, "\n");
                }
                $header = substr($line, 11);
                $path = 'ROOT help' === $header ? '' : trim((string) preg_replace('/^help ?/', '', $header));
                $buffer = '';
            } else {
                $buffer .= $line . "\n";
            }
        }
        if (null !== $path) {
            $blocks[$path] = rtrim($buffer, "\n");
        }

        foreach ($blocks as $commandPath => $expected) {
            yield ('' === $commandPath ? 'root' : $commandPath) => [$commandPath, $expected];
        }
    }

    #[DataProvider('helpProvider')]
    public function testHelpMatchesUpstream(string $commandPath, string $expected): void
    {
        $catalog = new CommandCatalog();
        $renderer = new HelpRenderer();

        $tokens = '' === $commandPath ? [] : explode(' ', $commandPath);
        $node = $catalog->resolve($tokens);

        self::assertNotNull($node, sprintf('command path "%s" not found', $commandPath));
        self::assertSame($expected, rtrim($renderer->render($node, self::SSH_COMMAND), "\n"));
    }

    public function testSshCommandFromPublicUrl(): void
    {
        self::assertSame('ssh -p 2223 localhost', HelpRenderer::sshCommandFromPublicUrl('ssh://localhost:2223'));
        self::assertSame('ssh -p 2222 codes.sh', HelpRenderer::sshCommandFromPublicUrl('ssh://codes.sh:2222'));
        self::assertSame('ssh git.example.com', HelpRenderer::sshCommandFromPublicUrl('ssh://git.example.com:22'));
        self::assertSame('ssh -p 2222 alice@codes.sh', HelpRenderer::sshCommandFromPublicUrl('ssh://codes.sh:2222', 'alice'));
        self::assertSame('ssh alice@git.example.com', HelpRenderer::sshCommandFromPublicUrl('ssh://git.example.com:22', 'alice'));
    }

    public function testInterpolateSsh(): void
    {
        $template = "{ssh} signup | bash\n{ssh:<username>} signup | bash\n{ssh-base} info";

        // Without user in session
        $res1 = HelpRenderer::interpolateSsh($template, 'ssh codes.sh');
        self::assertSame(
            "ssh codes.sh signup | bash\nssh <username>@codes.sh signup | bash\nssh codes.sh info",
            $res1
        );

        // With user in session
        $res2 = HelpRenderer::interpolateSsh($template, 'ssh klmklm@codes.sh');
        self::assertSame(
            "ssh klmklm@codes.sh signup | bash\nssh <username>@codes.sh signup | bash\nssh codes.sh info",
            $res2
        );

        // With port and user in session
        $res3 = HelpRenderer::interpolateSsh($template, 'ssh -p 2222 klmklm@codes.sh');
        self::assertSame(
            "ssh -p 2222 klmklm@codes.sh signup | bash\nssh -p 2222 <username>@codes.sh signup | bash\nssh -p 2222 codes.sh info",
            $res3
        );
    }

    public function testRestrictedRootForUnknownKeyShowsCustomUserExample(): void
    {
        $catalog = new CommandCatalog();
        $renderer = new HelpRenderer();
        $root = $catalog->restrictedRootForUnknownKey();

        $rendered = $renderer->render($root, 'ssh klmklm@codes.sh');
        self::assertStringContainsString('ssh klmklm@codes.sh signup | bash', $rendered);
        self::assertStringContainsString('ssh <username>@codes.sh signup | bash', $rendered);
        self::assertStringNotContainsString('ssh klmklm@codes.sh signup <username> | bash', $rendered);
    }

    public function testNonAdminCatalogHidesAdminCommands(): void
    {
        $catalog = new CommandCatalog();
        $userRoot = $catalog->root(false);

        $childNames = array_map(static fn($c) => $c->name, $userRoot->visibleChildren());
        self::assertNotContains('settings', $childNames);
        self::assertNotContains('user', $childNames);
        self::assertContains('repo', $childNames);
        self::assertContains('pubkey', $childNames);
        self::assertContains('token', $childNames);
        self::assertContains('info', $childNames);
        self::assertContains('about', $childNames);

        self::assertNull($catalog->resolve(['settings'], false));
        self::assertNull($catalog->resolve(['settings', 'allow-keyless'], false));
        self::assertNull($catalog->resolve(['user'], false));
        self::assertNull($catalog->resolve(['user', 'create'], false));
    }

    public function testDecoratedHelpPreservesRawLayoutWhenStripped(): void
    {
        $catalog = new CommandCatalog();
        $renderer = new HelpRenderer();
        $node = $catalog->root(true);

        $raw = $renderer->render($node, self::SSH_COMMAND, false);
        $decorated = $renderer->render($node, self::SSH_COMMAND, true);

        self::assertNotSame($raw, $decorated);
        self::assertStringContainsString("\e[", $decorated);

        // Stripping ANSI escape codes should yield exact layout equivalence
        $stripped = (string) preg_replace('/\e\[[0-9;]*m/', '', $decorated);
        self::assertSame($raw, $stripped);
    }

    public function testDecoratedSubcommandAppliesTokyoNightThemeTokens(): void
    {
        $catalog = new CommandCatalog();
        $renderer = new HelpRenderer();
        $node = $catalog->resolve(['repo', 'list'], true);
        self::assertNotNull($node);

        $decorated = $renderer->render($node, self::SSH_COMMAND, true);

        // Section header in Blue + bold
        self::assertStringContainsString("\e[38;2;122;162;247m\e[1mUsage:\e[0m", $decorated);
        self::assertStringContainsString("\e[38;2;122;162;247m\e[1mFlags:\e[0m", $decorated);

        // Flags in Orange
        self::assertStringContainsString("\e[38;2;255;158;100m", $decorated);

        // Layout stripped matches raw
        $raw = $renderer->render($node, self::SSH_COMMAND, false);
        $stripped = (string) preg_replace('/\e\[[0-9;]*m/', '', $decorated);
        self::assertSame($raw, $stripped);
    }

    public function testDecoratedHelpAppliesCliTokenHighlightingToExamples(): void
    {
        $catalog = new CommandCatalog();
        $renderer = new HelpRenderer();
        $node = $catalog->resolve(['repo', 'create'], true);
        self::assertNotNull($node);

        $decorated = $renderer->render($node, self::SSH_COMMAND, true);

        // Italic comment
        self::assertStringContainsString("\e[3m", $decorated);

        // Highlighted command tokens
        self::assertStringContainsString("\e[1mssh\e[0m", $decorated);
        self::assertStringContainsString("\e[1mrepo\e[0m", $decorated);
        self::assertStringContainsString("\e[1mcreate\e[0m", $decorated);
        self::assertStringContainsString("\e[1m--private\e[0m", $decorated);
        self::assertStringContainsString('"Internal microservice"', $decorated);

        // Raw layout matches
        $raw = $renderer->render($node, self::SSH_COMMAND, false);
        $stripped = (string) preg_replace('/\e\[[0-9;]*m/', '', $decorated);
        self::assertSame($raw, $stripped);
    }

    public function testDecoratedHelpDifferentiatesUsageArgumentsFromCommands(): void
    {
        $catalog = new CommandCatalog();
        $renderer = new HelpRenderer();
        $node = $catalog->resolve(['repo', 'create'], true);
        self::assertNotNull($node);

        $decorated = $renderer->render($node, self::SSH_COMMAND, true);

        // Commands in Cyan bold
        self::assertStringContainsString("\e[38;2;125;207;255m\e[1mrepo\e[0m", $decorated);
        self::assertStringContainsString("\e[38;2;125;207;255m\e[1mcreate\e[0m", $decorated);

        // Argument placeholder in Teal bold
        self::assertStringContainsString("\e[38;2;42;195;222m\e[1mREPOSITORY\e[0m", $decorated);

        // Flags marker in Muted italic
        self::assertStringContainsString("\e[38;2;86;95;137m\e[3m[flags]\e[0m", $decorated);
    }

    public function testDecoratedHelpStylesScopeAndFlagsInDescriptions(): void
    {
        $catalog = new CommandCatalog();
        $renderer = new HelpRenderer();
        $node = $catalog->resolve(['repo', 'create'], true);
        self::assertNotNull($node);

        $decorated = $renderer->render($node, self::SSH_COMMAND, true);

        // Scope keyword badge
        self::assertStringContainsString("\e[38;2;125;207;255m\e[1mAuthenticated user\e[0m", $decorated);

        // Flags header in description
        self::assertStringContainsString("\e[38;2;122;162;247m\e[1mFlags:\e[0m", $decorated);

        // Flags in description in Orange bold
        self::assertStringContainsString("\e[38;2;255;158;100m\e[1m-d\e[0m", $decorated);
        self::assertStringContainsString("\e[38;2;255;158;100m\e[1m--description\e[0m", $decorated);
    }

    public function testDecoratedHelpPreservesRawLayoutWhenStrippedOnAllCommands(): void
    {
        $catalog = new CommandCatalog();
        $renderer = new HelpRenderer();
        $root = $catalog->root(true);

        $checkNode = static function (CommandSpec $node, callable $checkNode) use ($renderer): void {
            $raw = $renderer->render($node, self::SSH_COMMAND, false);
            $decorated = $renderer->render($node, self::SSH_COMMAND, true);
            $stripped = (string) preg_replace('/\e\[[0-9;]*m/', '', $decorated);
            self::assertSame($raw, $stripped, sprintf('Command "%s" failed stripped equivalence', $node->name));

            foreach ($node->children as $child) {
                $checkNode($child, $checkNode);
            }
        };

        $checkNode($root, $checkNode);
    }
}
