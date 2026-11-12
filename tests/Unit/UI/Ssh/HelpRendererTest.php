<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Ssh;

use App\UI\Ssh\Help\CommandCatalog;
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

        self::assertNull($catalog->resolve(['settings'], false));
        self::assertNull($catalog->resolve(['settings', 'allow-keyless'], false));
        self::assertNull($catalog->resolve(['user'], false));
        self::assertNull($catalog->resolve(['user', 'create'], false));
    }
}
