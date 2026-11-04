<?php

declare(strict_types=1);

namespace App\Service\CodeIntel;

use App\Model\CodeIntel\OccurrenceModel;
use App\Model\CodeIntel\SymbolInfoModel;
use App\Model\CodeIntel\SymbolLocationModel;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

final class CodeIntelService
{
    private readonly LoggerInterface $logger;
    private string $storageDir;

    /**
     * In-memory cache of parsed SCIP indices.
     * Key: "$repo:$sha" -> parsed index array with docOccurrences, definitions, references.
     *
     * @var array<string, array{docOccurrences: array<string, OccurrenceModel[]>, definitions: array<string, SymbolLocationModel>, references: array<string, SymbolLocationModel[]>}>
     */
    private array $cache = [];

    public function __construct(
        private readonly ScipProtobufParser $parser = new ScipProtobufParser(),
        ?string $storageDir = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->storageDir = $storageDir ?? (dirname(__DIR__, 3).'/var/codeintel');
    }

    /**
     * Clears in-memory cache.
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Returns the filesystem path for storing a repository's commit SCIP index.
     */
    public function getStoragePath(string $repo, string $sha): string
    {
        $repoClean = trim(str_replace(['..', '\\'], ['', '/'], $repo), '/');

        return $this->storageDir.'/'.$repoClean.'/'.$sha.'.scip';
    }

    /**
     * Checks whether an index is available (in memory or on disk).
     */
    public function hasIndex(string $repo, string $sha): bool
    {
        $cacheKey = $this->cacheKey($repo, $sha);
        if (isset($this->cache[$cacheKey])) {
            return true;
        }

        return file_exists($this->getStoragePath($repo, $sha));
    }

    /**
     * Stores a binary SCIP index on disk and places its parsed structure in the in-memory cache.
     */
    public function storeIndex(string $repo, string $sha, string $scipBinary): void
    {
        $filePath = $this->getStoragePath($repo, $sha);
        $dir = dirname($filePath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Failed to create storage directory "%s"', $dir));
        }

        if (false === file_put_contents($filePath, $scipBinary)) {
            throw new \RuntimeException(sprintf('Failed to write SCIP index to "%s"', $filePath));
        }

        $parsed = $this->parseIndex($scipBinary);
        $this->cache[$this->cacheKey($repo, $sha)] = $parsed;
    }

    /**
     * Retrieves the parsed SCIP index from in-memory cache or storage file.
     *
     * @return array{docOccurrences: array<string, OccurrenceModel[]>, definitions: array<string, SymbolLocationModel>, references: array<string, SymbolLocationModel[]>}|null
     */
    public function getIndex(string $repo, string $sha): ?array
    {
        $cacheKey = $this->cacheKey($repo, $sha);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $filePath = $this->getStoragePath($repo, $sha);
        if (!file_exists($filePath)) {
            return null;
        }

        $binary = @file_get_contents($filePath);
        if (false === $binary) {
            return null;
        }

        $parsed = $this->parseIndex($binary);
        $this->cache[$cacheKey] = $parsed;

        return $parsed;
    }

    /**
     * Parses a binary SCIP index:
     * - Skips empty symbols and local symbols starting with "local "
     * - Deduplicates definitions and references
     * - Sorts occurrences per document by (startLine, startCol)
     *
     * @return array{docOccurrences: array<string, OccurrenceModel[]>, definitions: array<string, SymbolLocationModel>, references: array<string, SymbolLocationModel[]>}
     */
    public function parseIndex(string $scipBinary): array
    {
        $decoded = $this->parser->decodeIndex($scipBinary);

        /** @var array<string, OccurrenceModel[]> $docOccurrences */
        $docOccurrences = [];
        /** @var array<string, SymbolLocationModel> $definitions */
        $definitions = [];
        /** @var array<string, SymbolLocationModel[]> $references */
        $references = [];
        /** @var array<string, bool> $seenRefs */
        $seenRefs = [];

        foreach ($decoded['documents'] as $doc) {
            $rawPath = (string) ($doc['relative_path'] ?? '');
            $cleanPath = $this->cleanPath($rawPath);
            if (!isset($docOccurrences[$cleanPath])) {
                $docOccurrences[$cleanPath] = [];
            }

            foreach ($doc['occurrences'] ?? [] as $occ) {
                $symbol = (string) ($occ['symbol'] ?? '');
                // Skip empty symbols and local scoped symbols ("local ")
                if ('' === $symbol || str_starts_with($symbol, 'local ')) {
                    continue;
                }

                $rangeCoords = ScipProtobufParser::parseRange($occ['range'] ?? []);
                $roles = (int) ($occ['symbol_roles'] ?? 0);
                $isDef = ($roles & ScipProtobufParser::SYMBOL_ROLE_DEFINITION) !== 0;

                $occModel = new OccurrenceModel(
                    startLine: $rangeCoords['startLine'],
                    startCol: $rangeCoords['startCol'],
                    endLine: $rangeCoords['endLine'],
                    endCol: $rangeCoords['endCol'],
                    symbol: $symbol,
                    isDefinition: $isDef,
                );

                $docOccurrences[$cleanPath][] = $occModel;

                $loc = new SymbolLocationModel(
                    path: $cleanPath,
                    line: $rangeCoords['startLine'],
                    col: $rangeCoords['startCol'],
                    endLine: $rangeCoords['endLine'],
                    endCol: $rangeCoords['endCol'],
                );

                if ($isDef) {
                    if (!isset($definitions[$symbol])) {
                        $definitions[$symbol] = $loc;
                    }
                } else {
                    $refKey = $symbol.'|'.$cleanPath.'|'.$rangeCoords['startLine'].'|'.$rangeCoords['startCol'];
                    if (!isset($seenRefs[$refKey])) {
                        $seenRefs[$refKey] = true;
                        if (!isset($references[$symbol])) {
                            $references[$symbol] = [];
                        }
                        $references[$symbol][] = $loc;
                    }
                }
            }

            // Sort occurrences by (startLine, startCol)
            usort($docOccurrences[$cleanPath], static function (OccurrenceModel $a, OccurrenceModel $b): int {
                if ($a->startLine !== $b->startLine) {
                    return $a->startLine <=> $b->startLine;
                }

                return $a->startCol <=> $b->startCol;
            });
        }

        return [
            'docOccurrences' => $docOccurrences,
            'definitions' => $definitions,
            'references' => $references,
        ];
    }

    /**
     * Returns all occurrences in a document for a specific repository and commit.
     *
     * @return OccurrenceModel[]
     */
    public function getDocumentOccurrences(string $repo, string $sha, string $filePath): array
    {
        $idx = $this->getIndex($repo, $sha);
        if (null === $idx) {
            return [];
        }

        $clean = $this->cleanPath($filePath);

        return $idx['docOccurrences'][$clean] ?? [];
    }

    /**
     * Identifies the symbol at the given (line, col) cursor position in a document.
     * Line and col are 0-indexed.
     */
    public function symbolAt(string $repo, string $sha, string $filePath, int $line, int $col): ?string
    {
        $occs = $this->getDocumentOccurrences($repo, $sha, $filePath);
        foreach ($occs as $occ) {
            if ($occ->contains($line, $col)) {
                return $occ->symbol;
            }
        }

        return null;
    }

    /**
     * Finds the definition location of the symbol located at (line, col) in filePath.
     */
    public function findDefinition(string $repo, string $sha, string $filePath, int $line, int $col): ?SymbolLocationModel
    {
        $idx = $this->getIndex($repo, $sha);
        if (null === $idx) {
            return null;
        }

        $sym = $this->symbolAt($repo, $sha, $filePath, $line, $col);
        if (null === $sym) {
            return null;
        }

        return $idx['definitions'][$sym] ?? null;
    }

    /**
     * Finds all referencing locations for the symbol located at (line, col) in filePath.
     *
     * @return SymbolLocationModel[]
     */
    public function findReferences(string $repo, string $sha, string $filePath, int $line, int $col): array
    {
        $idx = $this->getIndex($repo, $sha);
        if (null === $idx) {
            return [];
        }

        $sym = $this->symbolAt($repo, $sha, $filePath, $line, $col);
        if (null === $sym) {
            return [];
        }

        return $idx['references'][$sym] ?? [];
    }

    /**
     * Finds the definition location directly given a symbol identifier.
     */
    public function findDefinitionForSymbol(string $repo, string $sha, string $symbol): ?SymbolLocationModel
    {
        $idx = $this->getIndex($repo, $sha);
        if (null === $idx || '' === $symbol) {
            return null;
        }

        return $idx['definitions'][$symbol] ?? null;
    }

    /**
     * Finds all referencing locations directly given a symbol identifier.
     *
     * @return SymbolLocationModel[]
     */
    public function findReferencesForSymbol(string $repo, string $sha, string $symbol): array
    {
        $idx = $this->getIndex($repo, $sha);
        if (null === $idx || '' === $symbol) {
            return [];
        }

        return $idx['references'][$symbol] ?? [];
    }

    /**
     * Aggregates definitions and references for a symbol into a SymbolInfoModel.
     */
    public function getSymbolInfo(string $repo, string $sha, string $symbol): ?SymbolInfoModel
    {
        $idx = $this->getIndex($repo, $sha);
        if (null === $idx || '' === $symbol) {
            return null;
        }

        $def = $idx['definitions'][$symbol] ?? null;
        $defs = null !== $def ? [$def] : [];
        $refs = $idx['references'][$symbol] ?? [];

        if (empty($defs) && empty($refs)) {
            return null;
        }

        return new SymbolInfoModel($symbol, $defs, $refs);
    }

    /**
     * Checks if a directory looks like a PHP or Symfony project.
     * Examines composer.json, symfony.lock, bin/console, or scans for .php files
     * while skipping .git, vendor, var, cache.
     */
    public function isPhpProject(string $dir): bool
    {
        if (file_exists($dir.'/composer.json') || file_exists($dir.'/symfony.lock') || file_exists($dir.'/bin/console')) {
            return true;
        }

        if (!is_dir($dir)) {
            return false;
        }

        try {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $cur): bool {
                        if ($cur->isDir()) {
                            $name = $cur->getFilename();

                            return !in_array($name, ['.git', 'vendor', 'var', 'cache'], true);
                        }

                        return true;
                    }
                )
            );

            foreach ($iter as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * Checks if a project exhibits Symfony framework indicators.
     */
    public function isSymfonyProject(string $dir): bool
    {
        if (file_exists($dir.'/symfony.lock') || file_exists($dir.'/bin/console') || file_exists($dir.'/config/bundles.php')) {
            return true;
        }

        $composerPath = $dir.'/composer.json';
        if (file_exists($composerPath)) {
            $data = @file_get_contents($composerPath);
            if (false !== $data) {
                $c = json_decode($data, true);
                if (is_array($c)) {
                    $reqs = array_merge($c['require'] ?? [], $c['require-dev'] ?? []);
                    foreach (array_keys($reqs) as $pkg) {
                        if (str_starts_with((string) $pkg, 'symfony/')) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Pure-PHP project indexer porting pkg/codeintel/php.go into native PHP.
     * Emits SCIP documents for classes, methods, imports, and references.
     *
     * @return array<int, array{relative_path: string, occurrences: array<int, array{range: int[], symbol: string, symbol_roles: int}>, language: string}>
     */
    public function indexPhpProject(string $projectRoot): array
    {
        $pkgName = 'app';
        $version = 'dev';

        $cpath = $projectRoot.'/composer.json';
        if (file_exists($cpath)) {
            $cdata = json_decode((string) @file_get_contents($cpath), true);
            if (is_array($cdata)) {
                if (!empty($cdata['name'])) {
                    $pkgName = (string) $cdata['name'];
                }
                if (!empty($cdata['version'])) {
                    $version = (string) $cdata['version'];
                }
            }
        }

        $phpFiles = [];
        try {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($projectRoot, \FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $cur): bool {
                        if ($cur->isDir()) {
                            $name = $cur->getFilename();

                            return !in_array($name, ['.git', 'vendor', 'var', 'cache'], true);
                        }

                        return true;
                    }
                )
            );

            foreach ($iter as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($projectRoot) + 1));
                    $phpFiles[] = $rel;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Error scanning directory for PHP files: '.$e->getMessage());
        }

        /** @var array<int, array{relPath: string, tokens: array<int, array{id: ?int, text: string, line: int, startCol: int, endLine: int, endCol: int}>}> $allFiles */
        $allFiles = [];
        foreach ($phpFiles as $rel) {
            $content = (string) @file_get_contents($projectRoot.'/'.$rel);
            $rawTokens = token_get_all($content);
            $tokenList = [];
            $line = 0;
            $col = 0;
            foreach ($rawTokens as $t) {
                $text = is_array($t) ? $t[1] : $t;
                $id = is_array($t) ? $t[0] : null;
                $startLine = $line;
                $startCol = $col;
                $lines = explode("\n", $text);
                if (count($lines) > 1) {
                    $line += count($lines) - 1;
                    $col = strlen(end($lines));
                } else {
                    $col += strlen($text);
                }
                $tokenList[] = [
                    'id' => $id,
                    'text' => $text,
                    'line' => $startLine,
                    'startCol' => $startCol,
                    'endLine' => $line,
                    'endCol' => $col,
                ];
            }
            $allFiles[] = ['relPath' => $rel, 'tokens' => $tokenList];
        }

        // Pass 1: Collect definitions
        $definitions = []; // fqn -> def
        $shortNameToFQNs = []; // short -> fqn[]

        $makeClassSymbol = static function (string $fqn) use ($pkgName, $version): string {
            $norm = str_replace('\\', '/', ltrim($fqn, '\\'));

            return "scip-php composer {$pkgName} {$version} {$norm}#";
        };

        $makeMethodSymbol = static function (string $classFQN, string $meth) use ($pkgName, $version): string {
            $norm = str_replace('\\', '/', ltrim($classFQN, '\\'));

            return "scip-php composer {$pkgName} {$version} {$norm}#{$meth}().";
        };

        foreach ($allFiles as $ft) {
            $tokens = $ft['tokens'];
            $n = count($tokens);
            $curNamespace = '';
            $curClass = '';
            $braceDepth = 0;
            $classBraceDepth = -1;

            for ($i = 0; $i < $n; ++$i) {
                $t = $tokens[$i];
                if ('{' === $t['text']) {
                    ++$braceDepth;
                    continue;
                }
                if ('}' === $t['text']) {
                    --$braceDepth;
                    if (-1 !== $classBraceDepth && $braceDepth <= $classBraceDepth) {
                        $curClass = '';
                        $classBraceDepth = -1;
                    }
                    continue;
                }

                if (\T_NAMESPACE === $t['id']) {
                    $j = $i + 1;
                    while ($j < $n && \T_WHITESPACE === $tokens[$j]['id']) {
                        ++$j;
                    }
                    if ($j < $n && in_array($tokens[$j]['id'], [\T_STRING, \T_NAME_QUALIFIED], true)) {
                        $curNamespace = $tokens[$j]['text'];
                        $i = $j;
                    }
                    continue;
                }

                if (in_array($t['id'], [\T_CLASS, \T_INTERFACE, \T_TRAIT, defined('T_ENUM') ? \T_ENUM : -1], true)) {
                    // Ignore ::class
                    $prev = $i - 1;
                    while ($prev >= 0 && \T_WHITESPACE === $tokens[$prev]['id']) {
                        --$prev;
                    }
                    if ($prev >= 0 && \T_DOUBLE_COLON === $tokens[$prev]['id']) {
                        continue;
                    }

                    $j = $i + 1;
                    while ($j < $n && \T_WHITESPACE === $tokens[$j]['id']) {
                        ++$j;
                    }
                    if ($j < $n && \T_STRING === $tokens[$j]['id']) {
                        $className = $tokens[$j]['text'];
                        $fqn = '' !== $curNamespace ? $curNamespace.'\\'.$className : $className;
                        $definitions[$fqn] = [
                            'symbol' => $makeClassSymbol($fqn),
                            'fqn' => $fqn,
                            'path' => $ft['relPath'],
                            'line' => $tokens[$j]['line'],
                            'startCol' => $tokens[$j]['startCol'],
                            'endLine' => $tokens[$j]['endLine'],
                            'endCol' => $tokens[$j]['endCol'],
                        ];
                        $shortNameToFQNs[$className][] = $fqn;
                        $curClass = $fqn;
                        $classBraceDepth = $braceDepth;
                        $i = $j;
                    }
                    continue;
                }

                if (\T_FUNCTION === $t['id']) {
                    $j = $i + 1;
                    while ($j < $n && \T_WHITESPACE === $tokens[$j]['id']) {
                        ++$j;
                    }
                    if ($j < $n && \T_STRING === $tokens[$j]['id']) {
                        $methName = $tokens[$j]['text'];
                        if ('' !== $curClass) {
                            $methKey = $curClass.'#'.$methName;
                            $definitions[$methKey] = [
                                'symbol' => $makeMethodSymbol($curClass, $methName),
                                'fqn' => $methKey,
                                'path' => $ft['relPath'],
                                'line' => $tokens[$j]['line'],
                                'startCol' => $tokens[$j]['startCol'],
                                'endLine' => $tokens[$j]['endLine'],
                                'endCol' => $tokens[$j]['endCol'],
                            ];
                        }
                        $i = $j;
                    }
                    continue;
                }
            }
        }

        // Pass 2: Extract occurrences (definitions + references)
        $documents = [];

        foreach ($allFiles as $ft) {
            $tokens = $ft['tokens'];
            $n = count($tokens);
            $curNamespace = '';
            $curClass = '';
            $braceDepth = 0;
            $classBraceDepth = -1;
            $imports = [];

            // Scan namespace and use imports
            for ($i = 0; $i < $n; ++$i) {
                $t = $tokens[$i];
                if (\T_NAMESPACE === $t['id']) {
                    $j = $i + 1;
                    while ($j < $n && \T_WHITESPACE === $tokens[$j]['id']) {
                        ++$j;
                    }
                    if ($j < $n && in_array($tokens[$j]['id'], [\T_STRING, \T_NAME_QUALIFIED], true)) {
                        $curNamespace = $tokens[$j]['text'];
                    }
                }
                if (\T_USE === $t['id']) {
                    $j = $i + 1;
                    while ($j < $n && \T_WHITESPACE === $tokens[$j]['id']) {
                        ++$j;
                    }
                    if ($j < $n && in_array($tokens[$j]['id'], [\T_STRING, \T_NAME_QUALIFIED], true)) {
                        $fullFQN = $tokens[$j]['text'];
                        $parts = explode('\\', $fullFQN);
                        $short = end($parts);
                        $alias = $short;

                        $k = $j + 1;
                        while ($k < $n && \T_WHITESPACE === $tokens[$k]['id']) {
                            ++$k;
                        }
                        if ($k < $n && \T_AS === $tokens[$k]['id']) {
                            $l = $k + 1;
                            while ($l < $n && \T_WHITESPACE === $tokens[$l]['id']) {
                                ++$l;
                            }
                            if ($l < $n && \T_STRING === $tokens[$l]['id']) {
                                $alias = $tokens[$l]['text'];
                            }
                        }
                        $imports[$alias] = $fullFQN;
                    }
                }
            }

            $resolveClass = static function (string $ident) use ($imports, $curNamespace, $definitions, $shortNameToFQNs): ?string {
                $clean = ltrim($ident, '\\');
                if (isset($imports[$clean])) {
                    return $imports[$clean];
                }
                if ('' !== $curNamespace) {
                    $same = $curNamespace.'\\'.$clean;
                    if (isset($definitions[$same])) {
                        return $same;
                    }
                }
                if (isset($definitions[$clean])) {
                    return $clean;
                }
                if (isset($shortNameToFQNs[$clean]) && 1 === count($shortNameToFQNs[$clean])) {
                    return $shortNameToFQNs[$clean][0];
                }

                return null;
            };

            $occs = [];
            $seen = [];
            $addOcc = static function (string $sym, int $line, int $startCol, int $endCol, bool $isDef) use (&$occs, &$seen): void {
                $k = "{$line}:{$startCol}:{$endCol}";
                if (isset($seen[$k])) {
                    return;
                }
                $seen[$k] = true;
                $occs[] = [
                    'range' => [$line, $startCol, $endCol],
                    'symbol' => $sym,
                    'symbol_roles' => $isDef ? ScipProtobufParser::SYMBOL_ROLE_DEFINITION : 0,
                ];
            };

            for ($i = 0; $i < $n; ++$i) {
                $t = $tokens[$i];
                if ('{' === $t['text']) {
                    ++$braceDepth;
                    continue;
                }
                if ('}' === $t['text']) {
                    --$braceDepth;
                    if (-1 !== $classBraceDepth && $braceDepth <= $classBraceDepth) {
                        $curClass = '';
                        $classBraceDepth = -1;
                    }
                    continue;
                }

                // Class def
                if (in_array($t['id'], [\T_CLASS, \T_INTERFACE, \T_TRAIT, defined('T_ENUM') ? \T_ENUM : -1], true)) {
                    $prev = $i - 1;
                    while ($prev >= 0 && \T_WHITESPACE === $tokens[$prev]['id']) {
                        --$prev;
                    }
                    if ($prev >= 0 && \T_DOUBLE_COLON === $tokens[$prev]['id']) {
                        continue;
                    }

                    $j = $i + 1;
                    while ($j < $n && \T_WHITESPACE === $tokens[$j]['id']) {
                        ++$j;
                    }
                    if ($j < $n && \T_STRING === $tokens[$j]['id']) {
                        $className = $tokens[$j]['text'];
                        $fqn = '' !== $curNamespace ? $curNamespace.'\\'.$className : $className;
                        $addOcc($makeClassSymbol($fqn), $tokens[$j]['line'], $tokens[$j]['startCol'], $tokens[$j]['endCol'], true);
                        $curClass = $fqn;
                        $classBraceDepth = $braceDepth;
                        $i = $j;
                    }
                    continue;
                }

                // Method def
                if (\T_FUNCTION === $t['id']) {
                    $j = $i + 1;
                    while ($j < $n && \T_WHITESPACE === $tokens[$j]['id']) {
                        ++$j;
                    }
                    if ($j < $n && \T_STRING === $tokens[$j]['id']) {
                        if ('' !== $curClass) {
                            $addOcc($makeMethodSymbol($curClass, $tokens[$j]['text']), $tokens[$j]['line'], $tokens[$j]['startCol'], $tokens[$j]['endCol'], true);
                        }
                        $i = $j;
                    }
                    continue;
                }

                // Reference to class
                if (in_array($t['id'], [\T_STRING, \T_NAME_QUALIFIED], true)) {
                    $fqn = $resolveClass($t['text']);
                    if (null !== $fqn) {
                        $addOcc($makeClassSymbol($fqn), $t['line'], $t['startCol'], $t['endCol'], false);
                        continue;
                    }
                }
            }

            if (!empty($occs)) {
                $documents[] = [
                    'relative_path' => $ft['relPath'],
                    'language' => 'php',
                    'occurrences' => $occs,
                ];
            }
        }

        return $documents;
    }

    /**
     * Indexes a commit from a repository:
     * - Exports the commit tree via git archive into a temporary folder
     * - Detects project type (go.mod or PHP/Symfony)
     * - Runs scip-go or scip-php CLI if found in PATH, or runs native PHP indexer fallback
     * - Saves index to storage and in-memory cache
     */
    public function indexCommit(string $repoPath, string $repoName, string $sha): bool
    {
        $tmpDir = sys_get_temp_dir().'/soft_serve_codeintel_'.bin2hex(random_bytes(8));
        if (!mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            $this->logger->error('Failed to create temporary directory for codeintel indexing');

            return false;
        }

        try {
            // 1. Export commit tree via git archive
            $archiveProcess = new Process(['git', 'archive', '--format=tar', $sha], $repoPath);
            $archiveProcess->run();
            if (!$archiveProcess->isSuccessful()) {
                $this->logger->error('git archive failed: '.$archiveProcess->getErrorOutput());

                return false;
            }

            $tarFile = $tmpDir.'/archive.tar';
            file_put_contents($tarFile, $archiveProcess->getOutput());

            // Extract tar
            $extracted = false;
            try {
                $phar = new \PharData($tarFile);
                $phar->extractTo($tmpDir);
                $extracted = true;
            } catch (\Throwable) {
                // Fallback to tar -xf
                $extractProcess = new Process(['tar', '-xf', $tarFile, '-C', $tmpDir]);
                $extractProcess->run();
                if ($extractProcess->isSuccessful()) {
                    $extracted = true;
                }
            }

            @unlink($tarFile);
            if (!$extracted) {
                $this->logger->error('Failed to extract git archive tarball');

                return false;
            }

            $outputIndex = $tmpDir.'/index.scip';

            // 2. Language check and indexing
            if (file_exists($tmpDir.'/go.mod')) {
                // Go project
                $scipGoPath = $this->findExecutable('scip-go');
                if (null === $scipGoPath) {
                    $this->logger->debug('scip-go not found in PATH, skipping indexing', ['repo' => $repoName, 'sha' => $sha]);

                    return false;
                }

                $cmd = new Process([$scipGoPath, '--output', $outputIndex], $tmpDir);
                $cmd->run();
                if (!$cmd->isSuccessful() || !file_exists($outputIndex)) {
                    $this->logger->error('scip-go execution failed: '.$cmd->getErrorOutput());

                    return false;
                }
            } elseif ($this->isPhpProject($tmpDir)) {
                // PHP / Symfony project
                $indexed = false;
                $scipPhpPath = $this->findExecutable('scip-php');
                if (null !== $scipPhpPath) {
                    $cmd = new Process([$scipPhpPath], $tmpDir);
                    $cmd->run();
                    if ($cmd->isSuccessful() && file_exists($outputIndex)) {
                        $indexed = true;
                    }
                }

                if (!$indexed) {
                    // Native PHP indexer fallback
                    $docs = $this->indexPhpProject($tmpDir);
                    $binary = $this->parser->encodeIndex($docs);
                    file_put_contents($outputIndex, $binary);
                }
            } else {
                $this->logger->debug('Unsupported project type, skipping indexing', ['repo' => $repoName, 'sha' => $sha]);

                return false;
            }

            if (!file_exists($outputIndex)) {
                return false;
            }

            $binary = (string) file_get_contents($outputIndex);
            $this->storeIndex($repoName, $sha, $binary);
            $this->logger->info('Successfully indexed commit with SCIP', ['repo' => $repoName, 'sha' => $sha]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Exception during indexCommit: '.$e->getMessage());

            return false;
        } finally {
            $this->removeDirectory($tmpDir);
        }
    }

    private function cleanPath(string $p): string
    {
        $p = str_replace('\\', '/', $p);
        $p = ltrim($p, './');
        $p = preg_replace('#/{2,}#', '/', $p) ?? $p;

        return trim($p, '/');
    }

    private function cacheKey(string $repo, string $sha): string
    {
        return $repo.':'.$sha;
    }

    private function findExecutable(string $name): ?string
    {
        $pathEnv = getenv('PATH') ?: '';
        $dirs = explode(\PATH_SEPARATOR, $pathEnv);
        foreach ($dirs as $d) {
            $candidate = rtrim($d, '/\\').\DIRECTORY_SEPARATOR.$name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                if ($fileinfo->isDir()) {
                    @rmdir($fileinfo->getRealPath());
                } else {
                    @unlink($fileinfo->getRealPath());
                }
            }
            @rmdir($dir);
        } catch (\Throwable) {
            // Best effort
        }
    }
}
