<?php

declare(strict_types=1);

namespace App\CodeIntel\Infrastructure\Indexer;

use App\CodeIntel\Infrastructure\Scip\ScipProtobufCodec;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Always-available pure-PHP SCIP indexer (lowest priority), porting the legacy
 * CodeIntelService::indexPhpProject `token_get_all` two-pass analysis. Emits occurrences for
 * class/interface/trait/enum and method definitions plus resolved class references, then encodes
 * them with {@see ScipProtobufCodec}. This is the fallback used when `scip-php` is unavailable.
 *
 * Deviation: the project-type detectors are exposed as public static helpers (shared with
 * {@see ScipPhpIndexer} and the tests) rather than instance methods on a monolithic service.
 */
#[AsTaggedItem(priority: 10)]
final class NativePhpIndexer implements Indexer
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ScipProtobufCodec $codec,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(string $projectDir): bool
    {
        return self::isPhpProject($projectDir);
    }

    public function index(string $projectDir): ?string
    {
        return $this->codec->encodeIndex($this->buildDocuments($projectDir));
    }

    /**
     * Checks if a directory looks like a PHP or Symfony project. Examines composer.json,
     * symfony.lock, bin/console, or scans for .php files while skipping .git, vendor, var, cache.
     */
    public static function isPhpProject(string $dir): bool
    {
        if (file_exists($dir . '/composer.json') || file_exists($dir . '/symfony.lock') || file_exists($dir . '/bin/console')) {
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
                            return !\in_array($cur->getFilename(), ['.git', 'vendor', 'var', 'cache'], true);
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
    public static function isSymfonyProject(string $dir): bool
    {
        if (file_exists($dir . '/symfony.lock') || file_exists($dir . '/bin/console') || file_exists($dir . '/config/bundles.php')) {
            return true;
        }

        $composerPath = $dir . '/composer.json';
        if (file_exists($composerPath)) {
            $data = @file_get_contents($composerPath);
            if (false !== $data) {
                $c = json_decode($data, true);
                if (\is_array($c)) {
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
     * Pure-PHP project indexer: emits SCIP documents for classes, methods, imports, and references.
     *
     * @return array<int, array{relative_path: string, occurrences: array<int, array{range: int[], symbol: string, symbol_roles: int}>, language: string}>
     */
    private function buildDocuments(string $projectRoot): array
    {
        $pkgName = 'app';
        $version = 'dev';

        $cpath = $projectRoot . '/composer.json';
        if (file_exists($cpath)) {
            $cdata = json_decode((string) @file_get_contents($cpath), true);
            if (\is_array($cdata)) {
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
                            return !\in_array($cur->getFilename(), ['.git', 'vendor', 'var', 'cache'], true);
                        }

                        return true;
                    }
                )
            );

            foreach ($iter as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $rel = str_replace('\\', '/', substr($file->getPathname(), \strlen($projectRoot) + 1));
                    $phpFiles[] = $rel;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Error scanning directory for PHP files: ' . $e->getMessage());
        }

        /** @var array<int, array{relPath: string, tokens: array<int, array{id: ?int, text: string, line: int, startCol: int, endLine: int, endCol: int}>}> $allFiles */
        $allFiles = [];
        foreach ($phpFiles as $rel) {
            $content = (string) @file_get_contents($projectRoot . '/' . $rel);
            $rawTokens = token_get_all($content);
            $tokenList = [];
            $line = 0;
            $col = 0;
            foreach ($rawTokens as $t) {
                $text = \is_array($t) ? $t[1] : $t;
                $id = \is_array($t) ? $t[0] : null;
                $startLine = $line;
                $startCol = $col;
                $lines = explode("\n", $text);
                if (\count($lines) > 1) {
                    $line += \count($lines) - 1;
                    $col = \strlen(end($lines));
                } else {
                    $col += \strlen($text);
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

        // Pass 1: Collect definitions.
        $definitions = []; // fqn -> def
        $shortNameToFQNs = []; // short -> fqn[]
        $methodNameToSymbols = []; // methodName -> symbol[] (every class defining it)

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
            $n = \count($tokens);
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
                    if ($j < $n && \in_array($tokens[$j]['id'], [\T_STRING, \T_NAME_QUALIFIED], true)) {
                        $curNamespace = $tokens[$j]['text'];
                        $i = $j;
                    }
                    continue;
                }

                if (\in_array($t['id'], [\T_CLASS, \T_INTERFACE, \T_TRAIT, \defined('T_ENUM') ? \T_ENUM : -1], true)) {
                    // Ignore ::class.
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
                        $fqn = '' !== $curNamespace ? $curNamespace . '\\' . $className : $className;
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
                            $methKey = $curClass . '#' . $methName;
                            $definitions[$methKey] = [
                                'symbol' => $makeMethodSymbol($curClass, $methName),
                                'fqn' => $methKey,
                                'path' => $ft['relPath'],
                                'line' => $tokens[$j]['line'],
                                'startCol' => $tokens[$j]['startCol'],
                                'endLine' => $tokens[$j]['endLine'],
                                'endCol' => $tokens[$j]['endCol'],
                            ];
                            $methodNameToSymbols[$methName][] = $makeMethodSymbol($curClass, $methName);
                        }
                        $i = $j;
                    }
                    continue;
                }
            }
        }

        // Pass 2: Extract occurrences (definitions + references).
        $documents = [];

        foreach ($allFiles as $ft) {
            $tokens = $ft['tokens'];
            $n = \count($tokens);
            $curNamespace = '';
            $curClass = '';
            $braceDepth = 0;
            $classBraceDepth = -1;
            $imports = [];

            // Scan namespace and use imports.
            for ($i = 0; $i < $n; ++$i) {
                $t = $tokens[$i];
                if (\T_NAMESPACE === $t['id']) {
                    $j = $i + 1;
                    while ($j < $n && \T_WHITESPACE === $tokens[$j]['id']) {
                        ++$j;
                    }
                    if ($j < $n && \in_array($tokens[$j]['id'], [\T_STRING, \T_NAME_QUALIFIED], true)) {
                        $curNamespace = $tokens[$j]['text'];
                    }
                }
                if (\T_USE === $t['id']) {
                    $j = $i + 1;
                    while ($j < $n && \T_WHITESPACE === $tokens[$j]['id']) {
                        ++$j;
                    }
                    if ($j < $n && \in_array($tokens[$j]['id'], [\T_STRING, \T_NAME_QUALIFIED], true)) {
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
                    $same = $curNamespace . '\\' . $clean;
                    if (isset($definitions[$same])) {
                        return $same;
                    }
                }
                if (isset($definitions[$clean])) {
                    return $clean;
                }
                if (isset($shortNameToFQNs[$clean]) && 1 === \count($shortNameToFQNs[$clean])) {
                    return $shortNameToFQNs[$clean][0];
                }

                return null;
            };

            $occs = [];
            $seen = [];
            $addOcc = static function (string $sym, int $line, int $startCol, int $endCol, bool $isDef) use (&$occs, &$seen): void {
                $k = "{$line}:{$startCol}:{$endCol}:{$sym}";
                if (isset($seen[$k])) {
                    return;
                }
                $seen[$k] = true;
                $occs[] = [
                    'range' => [$line, $startCol, $endCol],
                    'symbol' => $sym,
                    'symbol_roles' => $isDef ? ScipProtobufCodec::SYMBOL_ROLE_DEFINITION : 0,
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

                // Class def.
                if (\in_array($t['id'], [\T_CLASS, \T_INTERFACE, \T_TRAIT, \defined('T_ENUM') ? \T_ENUM : -1], true)) {
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
                        $fqn = '' !== $curNamespace ? $curNamespace . '\\' . $className : $className;
                        $addOcc($makeClassSymbol($fqn), $tokens[$j]['line'], $tokens[$j]['startCol'], $tokens[$j]['endCol'], true);
                        $curClass = $fqn;
                        $classBraceDepth = $braceDepth;
                        $i = $j;
                    }
                    continue;
                }

                // Method def.
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

                // Reference to method call: ->meth(, ?->meth(, or ::meth(. The receiver type is
                // unknown to this untyped indexer, so the call is attributed to every class that
                // defines a method of that name (over-inclusive but surfaces all call sites).
                if (\T_STRING === $t['id']) {
                    $prev = $i - 1;
                    while ($prev >= 0 && \T_WHITESPACE === $tokens[$prev]['id']) {
                        --$prev;
                    }
                    if ($prev >= 0 && \in_array($tokens[$prev]['id'], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON], true)) {
                        $next = $i + 1;
                        while ($next < $n && \T_WHITESPACE === $tokens[$next]['id']) {
                            ++$next;
                        }
                        if ($next < $n && '(' === $tokens[$next]['text']) {
                            foreach ($methodNameToSymbols[$t['text']] ?? [] as $msym) {
                                $addOcc($msym, $t['line'], $t['startCol'], $t['endCol'], false);
                            }
                        }

                        // A member access is never a bare class reference; stop here.
                        continue;
                    }
                }

                // Reference to class.
                if (\in_array($t['id'], [\T_STRING, \T_NAME_QUALIFIED], true)) {
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
}
