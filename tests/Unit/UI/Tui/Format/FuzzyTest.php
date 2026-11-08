<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Tui\Format;

use App\UI\Tui\Format\Fuzzy;
use PHPUnit\Framework\TestCase;

final class FuzzyTest extends TestCase
{
    public function testSubsequenceMatchAndNonMatch(): void
    {
        $match = Fuzzy::path('calc', 'src/Domain/Calculator.php');
        self::assertNotNull($match);
        self::assertSame([11, 12, 13, 14], $match['positions']);

        self::assertNull(Fuzzy::path('xyz', 'src/Domain/Calculator.php'));
        self::assertNull(Fuzzy::path('calculator_extra', 'Calculator.php'));
    }

    public function testBasenameAndConsecutiveMatchesScoreHigherThanScatteredDirectoryMatches(): void
    {
        $exactBasename = Fuzzy::path('Calculator.php', 'src/Calculator.php');
        $partialDir = Fuzzy::path('Calculator.php', 'src/Calculator/ extra/file.php');

        self::assertNotNull($exactBasename);
        self::assertNotNull($partialDir);
        self::assertGreaterThan($partialDir['score'], $exactBasename['score']);

        // Boundary / camelCase match vs scattered match
        $boundary = Fuzzy::path('ft', 'src/UI/Tui/Page/Repo/FilesTab.php');
        $scattered = Fuzzy::path('ft', 'src/refactoring/notes.txt');

        self::assertNotNull($boundary);
        self::assertNotNull($scattered);
        self::assertGreaterThan($scattered['score'], $boundary['score']);
    }

    public function testMultiTokenSpaceSeparatedQueryRequiresAllTokens(): void
    {
        $match = Fuzzy::path('tui files', 'src/UI/Tui/Page/Repo/FilesTab.php');
        self::assertNotNull($match);
        self::assertNotEmpty($match['positions']);

        // Reversed token order still matches when both tokens match the path
        $reversed = Fuzzy::path('files tui', 'src/UI/Tui/Page/Repo/FilesTab.php');
        self::assertNotNull($reversed);

        // Fails if one token does not match
        self::assertNull(Fuzzy::path('tui nonexistent', 'src/UI/Tui/Page/Repo/FilesTab.php'));
    }

    public function testMultiFieldFuzzyScoringMatchesAcrossDistinctFields(): void
    {
        $commitFields = [
            ['text' => 'a1b2c3d', 'weight' => 3],
            ['text' => 'Fix authentication race condition', 'weight' => 3],
            ['text' => 'Alice Wonderland', 'weight' => 2],
            ['text' => 'alice@example.com', 'weight' => 2],
            ['text' => 'Detailed body explaining the mutex lock.', 'weight' => 1],
        ];

        // Matches token "alice" in author and "mutex" in body
        $score = Fuzzy::fields('alice mutex', $commitFields);
        self::assertNotNull($score);

        // Matches hash + title
        $hashTitleScore = Fuzzy::fields('a1b2 auth', $commitFields);
        self::assertNotNull($hashTitleScore);
        self::assertGreaterThan($score, $hashTitleScore);

        // Fails when a token matches no field
        self::assertNull(Fuzzy::fields('alice unknown_token', $commitFields));
    }
}
