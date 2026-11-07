<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Tui;

use App\UI\Tui\Page\Repo\AbstractRepoTab;
use App\UI\Tui\Page\Repo\FilesTab;
use PHPUnit\Framework\TestCase;

final class FilesTabFuzzyMatchTest extends TestCase
{
    public function testSubsequenceMatchAndNonMatch(): void
    {
        $match = FilesTab::fuzzyMatchPath('calc', 'src/Domain/Calculator.php');
        self::assertNotNull($match);
        self::assertSame([11, 12, 13, 14], $match['positions']);

        self::assertNull(FilesTab::fuzzyMatchPath('xyz', 'src/Domain/Calculator.php'));
        self::assertNull(FilesTab::fuzzyMatchPath('calculator_extra', 'Calculator.php'));
    }

    public function testBasenameAndConsecutiveMatchesScoreHigherThanScatteredDirectoryMatches(): void
    {
        $exactBasename = FilesTab::fuzzyMatchPath('Calculator.php', 'src/Calculator.php');
        $partialDir = FilesTab::fuzzyMatchPath('Calculator.php', 'src/Calculator/ extra/file.php');

        self::assertNotNull($exactBasename);
        self::assertNotNull($partialDir);
        self::assertGreaterThan($partialDir['score'], $exactBasename['score']);

        // Boundary / camelCase match vs scattered match
        $boundary = FilesTab::fuzzyMatchPath('ft', 'src/UI/Tui/Page/Repo/FilesTab.php');
        $scattered = FilesTab::fuzzyMatchPath('ft', 'src/refactoring/notes.txt');

        self::assertNotNull($boundary);
        self::assertNotNull($scattered);
        self::assertGreaterThan($scattered['score'], $boundary['score']);
    }

    public function testMultiTokenSpaceSeparatedQueryRequiresAllTokens(): void
    {
        $match = FilesTab::fuzzyMatchPath('tui files', 'src/UI/Tui/Page/Repo/FilesTab.php');
        self::assertNotNull($match);
        self::assertNotEmpty($match['positions']);

        // Reversed token order still matches when both tokens match the path
        $reversed = FilesTab::fuzzyMatchPath('files tui', 'src/UI/Tui/Page/Repo/FilesTab.php');
        self::assertNotNull($reversed);

        // Fails if one token does not match
        self::assertNull(FilesTab::fuzzyMatchPath('tui nonexistent', 'src/UI/Tui/Page/Repo/FilesTab.php'));
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
        $score = AbstractRepoTab::fuzzyScoreFields('alice mutex', $commitFields);
        self::assertNotNull($score);

        // Matches hash + title
        $hashTitleScore = AbstractRepoTab::fuzzyScoreFields('a1b2 auth', $commitFields);
        self::assertNotNull($hashTitleScore);
        self::assertGreaterThan($score, $hashTitleScore);

        // Fails when a token matches no field
        self::assertNull(AbstractRepoTab::fuzzyScoreFields('alice unknown_token', $commitFields));
    }
}
