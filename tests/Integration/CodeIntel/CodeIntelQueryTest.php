<?php

declare(strict_types=1);

namespace App\Tests\Integration\CodeIntel;

use App\CodeIntel\Application\Query\CodeIntelQuery;
use App\CodeIntel\Infrastructure\Scip\ScipProtobufCodec;

/**
 * Port of index_test.go: TestParseAndQuery (legacy CodeIntelServiceTest::testEncodeAndParseScipIndex).
 *
 * Encodes a binary .scip index with multi-file definitions, references, 3- and 4-element ranges,
 * and local symbols (ignored), then queries definitions, references and symbol info through the
 * pinned {@see CodeIntelQuery} API.
 */
final class CodeIntelQueryTest extends CodeIntelTestCase
{
    public function testEncodeAndParseScipIndex(): void
    {
        $codec = new ScipProtobufCodec();
        $docs = [
            [
                'relative_path' => 'main.go',
                'language' => 'go',
                'occurrences' => [
                    [
                        'range' => [2, 5, 11], // 3-element range: line 2, col 5..11 (Definition)
                        'symbol' => 'example.com/test#Helper().',
                        'symbol_roles' => ScipProtobufCodec::SYMBOL_ROLE_DEFINITION,
                    ],
                    [
                        'range' => [6, 1, 7], // 3-element range: line 6, col 1..7 (Reference)
                        'symbol' => 'example.com/test#Helper().',
                        'symbol_roles' => 0,
                    ],
                    [
                        'range' => [4, 1, 5],
                        'symbol' => 'local 1', // Local symbol: MUST be ignored
                        'symbol_roles' => 0,
                    ],
                    [
                        'range' => [15, 2, 17, 8], // 4-element range: line 15..17 (Definition)
                        'symbol' => 'example.com/test#MultiLine().',
                        'symbol_roles' => ScipProtobufCodec::SYMBOL_ROLE_DEFINITION,
                    ],
                ],
            ],
            [
                'relative_path' => 'pkg/sub.go',
                'language' => 'go',
                'occurrences' => [
                    [
                        'range' => [10, 4, 10], // 3-element range (Reference)
                        'symbol' => 'example.com/test#Helper().',
                        'symbol_roles' => 0,
                    ],
                    [
                        'range' => [25, 0, 27, 4], // 4-element range (Reference)
                        'symbol' => 'example.com/test#MultiLine().',
                        'symbol_roles' => 0,
                    ],
                ],
            ],
        ];

        $scipBinary = $codec->encodeIndex($docs);
        self::assertNotEmpty($scipBinary);

        $store = $this->makeStore();
        $query = new CodeIntelQuery($store);

        $repo = 'my/repo';
        $sha = 'a1b2c3d4e5f6';

        // 1. Store index and verify presence.
        self::assertFalse($query->has($repo, $sha));
        $store->store($repo, $sha, $scipBinary);
        self::assertTrue($query->has($repo, $sha));
        self::assertFalse($query->has($repo, 'nonexistent'));

        // 2. Check occurrences in main.go (local 1 must be skipped).
        $occs = $query->occurrences($repo, $sha, 'main.go');
        self::assertCount(3, $occs);

        self::assertSame('example.com/test#Helper().', $occs[0]->symbol);
        self::assertTrue($occs[0]->isDefinition);
        self::assertSame(2, $occs[0]->startLine);
        self::assertSame(5, $occs[0]->startCol);
        self::assertSame(11, $occs[0]->endCol);

        self::assertSame('example.com/test#Helper().', $occs[1]->symbol);
        self::assertFalse($occs[1]->isDefinition);
        self::assertSame(6, $occs[1]->startLine);

        self::assertSame('example.com/test#MultiLine().', $occs[2]->symbol);
        self::assertTrue($occs[2]->isDefinition);
        self::assertSame(15, $occs[2]->startLine);
        self::assertSame(17, $occs[2]->endLine);

        // 3. Query definition from reference in main.go (line 6, col 3).
        $def = $query->findDefinition($repo, $sha, 'main.go', 6, 3);
        self::assertNotNull($def);
        self::assertSame('main.go', $def->path);
        self::assertSame(2, $def->line);
        self::assertSame(5, $def->col);

        // 4. Query definition from cross-file reference in pkg/sub.go (line 10, col 5).
        $defCross = $query->findDefinition($repo, $sha, 'pkg/sub.go', 10, 5);
        self::assertNotNull($defCross);
        self::assertSame('main.go', $defCross->path);
        self::assertSame(2, $defCross->line);
        self::assertSame(5, $defCross->col);

        // 5. Query multiline definition from pkg/sub.go (line 26, col 2).
        $defMulti = $query->findDefinition($repo, $sha, 'pkg/sub.go', 26, 2);
        self::assertNotNull($defMulti);
        self::assertSame('main.go', $defMulti->path);
        self::assertSame(15, $defMulti->line);
        self::assertSame(2, $defMulti->col);

        // 6. Query outside of any symbol returns null.
        self::assertNull($query->findDefinition($repo, $sha, 'main.go', 0, 0));
        self::assertNull($query->symbolAt($repo, $sha, 'main.go', 0, 0));

        // 7. Query references from definition in main.go (line 2, col 7).
        $refs = $query->findReferences($repo, $sha, 'main.go', 2, 7);
        self::assertCount(2, $refs);
        self::assertSame('main.go', $refs[0]->path);
        self::assertSame(6, $refs[0]->line);
        self::assertSame('pkg/sub.go', $refs[1]->path);
        self::assertSame(10, $refs[1]->line);

        // 8. Symbol info lookup.
        $symInfo = $query->symbolInfo($repo, $sha, 'example.com/test#Helper().');
        self::assertNotNull($symInfo);
        self::assertTrue($symInfo->hasDefinition());
        self::assertSame('main.go', $symInfo->primaryDefinition()?->path);
        self::assertSame(2, $symInfo->referenceCount());

        // 9. Unknown symbol yields null.
        self::assertNull($query->symbolInfo($repo, $sha, 'example.com/test#Missing().'));
    }

    /**
     * When exact references are empty (e.g. a typed indexer attributes call sites to a sibling
     * symbol), findReferencesByName proposes references matching the symbol's local name across
     * every indexed symbol, deduplicated and ordered by path/line/col.
     */
    public function testFindReferencesByNameFallback(): void
    {
        $codec = new ScipProtobufCodec();
        $docs = [
            [
                'relative_path' => 'src/A.php',
                'language' => 'php',
                'occurrences' => [
                    // Target method: defined, never referenced through its own symbol.
                    [
                        'range' => [3, 20, 26],
                        'symbol' => 'scip-php composer acme/app dev App/A#handle().',
                        'symbol_roles' => ScipProtobufCodec::SYMBOL_ROLE_DEFINITION,
                    ],
                ],
            ],
            [
                'relative_path' => 'src/B.php',
                'language' => 'php',
                'occurrences' => [
                    // Sibling symbol with the same local name, referenced twice.
                    [
                        'range' => [8, 12, 18],
                        'symbol' => 'scip-php composer acme/app dev App/B#handle().',
                        'symbol_roles' => 0,
                    ],
                    [
                        'range' => [2, 4, 10],
                        'symbol' => 'scip-php composer acme/app dev App/B#handle().',
                        'symbol_roles' => 0,
                    ],
                ],
            ],
        ];

        $store = $this->makeStore();
        $query = new CodeIntelQuery($store);
        $store->store('acme/app', 'sha1', $codec->encodeIndex($docs));

        $target = 'scip-php composer acme/app dev App/A#handle().';

        // Exact references for the target symbol are empty.
        self::assertSame([], $query->findReferencesByName('acme/app', 'missing', $target));
        $exact = $query->symbolInfo('acme/app', 'sha1', $target);
        self::assertNotNull($exact);
        self::assertSame(0, $exact->referenceCount());

        // Name-based fallback surfaces the sibling's two references, ordered by path/line/col.
        $byName = $query->findReferencesByName('acme/app', 'sha1', $target);
        self::assertCount(2, $byName);
        self::assertSame('src/B.php', $byName[0]->path);
        self::assertSame(2, $byName[0]->line);
        self::assertSame('src/B.php', $byName[1]->path);
        self::assertSame(8, $byName[1]->line);
    }
}
