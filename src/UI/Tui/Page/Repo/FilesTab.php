<?php

declare(strict_types=1);

namespace App\UI\Tui\Page\Repo;

use App\CodeIntel\Domain\SymbolLocation;
use App\UI\Tui\Input\Keys;
use App\UI\Tui\Render\Screen;

/**
 * Files tab: a directory browser over the current ref, opening a
 * syntax-highlighted code viewer with keyboard go-to-definition ('d') and
 * find-references ('r') backed by {@see \App\CodeIntel\Application\Query\CodeIntelQuery}.
 */
final class FilesTab extends AbstractRepoTab
{
    private bool $fileOpen = false;
    private string $filePath = '';
    /** @var list<string> */
    private array $fileRaw = [];
    private int $cursorLine = 0;
    private int $cursorCol = 0;

    private bool $refsOpen = false;
    /** @var list<SymbolLocation> */
    private array $refs = [];
    private int $refSel = 0;

    public function capturesInput(): bool
    {
        return $this->fileOpen || $this->refsOpen;
    }

    public function onActivate(): void
    {
        parent::onActivate();
        $this->fileOpen = false;
        $this->refsOpen = false;
    }

    public function footerText(): string
    {
        if ($this->refsOpen) {
            return ' ↑/↓ move · Enter: jump · Esc: back';
        }
        if ($this->fileOpen) {
            return sprintf(' Ln %d, Col %d · d: definition · r: references · Esc: back', $this->cursorLine + 1, $this->cursorCol + 1);
        }

        return self::BASE_FOOTER;
    }

    public function handleKey(string $seq): bool
    {
        if ($this->refsOpen) {
            return $this->handleRefsKey($seq);
        }
        if ($this->fileOpen) {
            return $this->handleFileKey($seq);
        }

        return $this->handleTabKey(Keys::normalize($seq), Keys::text($seq));
    }

    private function handleTabKey(string $tok, ?string $txt): bool
    {
        $entries = $this->ctx->tree();
        if (Keys::BACKSPACE === $tok && '' !== $this->ctx->subpath) {
            $this->ctx->subpath = $this->parentPath($this->ctx->subpath);
            $this->sel = 0;

            return true;
        }
        if (Keys::ENTER === $tok && [] !== $entries) {
            $entry = $entries[min($this->sel, \count($entries) - 1)];
            if ($entry->isDirectory()) {
                $this->ctx->subpath = $entry->path;
                $this->sel = 0;
            } else {
                $this->openFile($entry->path);
            }

            return true;
        }

        return $this->handleListMove($tok, $txt, \count($entries));
    }

    private function handleFileKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);
        $last = max(0, \count($this->fileRaw) - 1);

        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            $this->fileOpen = false;

            return true;
        }
        if (Keys::UP === $tok || 'k' === $txt) {
            $this->cursorLine = max(0, $this->cursorLine - 1);
        } elseif (Keys::DOWN === $tok || 'j' === $txt) {
            $this->cursorLine = min($last, $this->cursorLine + 1);
        } elseif (Keys::LEFT === $tok) {
            $this->cursorCol = max(0, $this->cursorCol - 1);
        } elseif (Keys::RIGHT === $tok) {
            $this->cursorCol = min($this->currentLineLength(), $this->cursorCol + 1);
        } elseif (Keys::PAGE_UP === $tok) {
            $this->cursorLine = max(0, $this->cursorLine - 10);
        } elseif (Keys::PAGE_DOWN === $tok) {
            $this->cursorLine = min($last, $this->cursorLine + 10);
        } elseif (Keys::HOME === $tok || 'g' === $txt) {
            $this->cursorLine = 0;
        } elseif (Keys::END === $tok || 'G' === $txt) {
            $this->cursorLine = $last;
        } elseif ('d' === $txt) {
            $this->gotoDefinition();
        } elseif ('r' === $txt) {
            $this->findReferences();
        } else {
            return false;
        }
        $this->cursorCol = min($this->cursorCol, $this->currentLineLength());

        return true;
    }

    private function handleRefsKey(string $seq): bool
    {
        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            $this->refsOpen = false;
        } elseif (Keys::UP === $tok || 'k' === $txt) {
            $this->refSel = max(0, $this->refSel - 1);
        } elseif (Keys::DOWN === $tok || 'j' === $txt) {
            $this->refSel = max(0, min($this->refSel + 1, \count($this->refs) - 1));
        } elseif (Keys::ENTER === $tok && [] !== $this->refs) {
            $this->refsOpen = false;
            $this->openLocation($this->refs[min($this->refSel, \count($this->refs) - 1)]);
        } else {
            return false;
        }

        return true;
    }

    // --- actions -----------------------------------------------------------

    private function openFile(string $path): void
    {
        try {
            $content = $this->ctx->reader->getBlob($this->ctx->git, $this->ctx->ref, $path);
        } catch (\Throwable) {
            $this->ctx->flash = 'Cannot open '.$path;

            return;
        }
        $this->fileRaw = preg_split('/\r\n|\r|\n/', rtrim($content, "\n")) ?: [''];
        $this->filePath = $path;
        $this->fileOpen = true;
        $this->cursorLine = 0;
        $this->cursorCol = 0;
    }

    private function gotoDefinition(): void
    {
        $sha = $this->ctx->sha();
        if ('' === $sha || !$this->ctx->codeIntel->has($this->ctx->name, $sha)) {
            $this->ctx->flash = 'No code intelligence index for this commit.';

            return;
        }
        $def = $this->ctx->codeIntel->findDefinition($this->ctx->name, $sha, $this->filePath, $this->cursorLine, $this->cursorCol);
        if (null === $def) {
            $this->ctx->flash = 'No definition found at cursor.';

            return;
        }
        $this->openLocation($def);
    }

    private function findReferences(): void
    {
        $sha = $this->ctx->sha();
        if ('' === $sha || !$this->ctx->codeIntel->has($this->ctx->name, $sha)) {
            $this->ctx->flash = 'No code intelligence index for this commit.';

            return;
        }
        $refs = $this->ctx->codeIntel->findReferences($this->ctx->name, $sha, $this->filePath, $this->cursorLine, $this->cursorCol);
        if ([] === $refs) {
            $this->ctx->flash = 'No references found at cursor.';

            return;
        }
        $this->refs = $refs;
        $this->refSel = 0;
        $this->refsOpen = true;
    }

    private function openLocation(SymbolLocation $loc): void
    {
        if ($loc->path !== $this->filePath) {
            try {
                $content = $this->ctx->reader->getBlob($this->ctx->git, $this->ctx->ref, $loc->path);
            } catch (\Throwable) {
                $this->ctx->flash = 'Cannot open '.$loc->path;

                return;
            }
            $this->fileRaw = preg_split('/\r\n|\r|\n/', rtrim($content, "\n")) ?: [''];
            $this->filePath = $loc->path;
            $this->fileOpen = true;
        }
        $this->cursorLine = max(0, min($loc->line, \count($this->fileRaw) - 1));
        $this->cursorCol = max(0, $loc->col);
        $this->ctx->flash = '→ '.$loc->path.':'.($loc->line + 1);
    }

    private function currentLineLength(): int
    {
        $line = $this->fileRaw[$this->cursorLine] ?? '';

        return mb_strlen($line);
    }

    private function parentPath(string $path): string
    {
        $pos = strrpos($path, '/');

        return false === $pos ? '' : substr($path, 0, $pos);
    }

    // --- rendering ---------------------------------------------------------

    public function paintBody(int $cols, int $rows): array
    {
        if ($this->refsOpen) {
            return $this->paintRefs($cols, $rows);
        }
        if ($this->fileOpen) {
            return $this->paintFile($cols, $rows);
        }

        return $this->paintFiles($cols, $rows);
    }

    /**
     * @return list<string>
     */
    private function paintFiles(int $cols, int $rows): array
    {
        $entries = $this->ctx->tree();
        $crumb = '  /'.$this->ctx->subpath;
        $header = "\e[2m".Screen::fit($crumb, $cols)."\e[0m";
        $bodyRows = max(1, $rows - 1);

        if ([] === $entries) {
            return array_merge([$header], $this->messageBody('Empty directory.', $cols));
        }

        $count = \count($entries);
        $sel = min($this->sel, $count - 1);
        $top = $count > $bodyRows ? max(0, min($sel - intdiv($bodyRows, 2), $count - $bodyRows)) : 0;

        $out = [$header];
        for ($r = 0; $r < $bodyRows; ++$r) {
            $idx = $top + $r;
            if ($idx >= $count) {
                $out[] = Screen::blank($cols);
                continue;
            }
            $entry = $entries[$idx];
            $size = $entry->isFile() && null !== $entry->size ? $this->humanSize($entry->size) : '';
            $out = array_merge($out, Screen::fileRow(
                $this->modeString($entry),
                $size,
                $entry->name,
                $entry->isDirectory(),
                $idx === $sel,
                $cols,
            ));
        }

        return $out;
    }

    public function statusInfo(): string
    {
        $count = \count($this->ctx->tree());
        if (0 === $count) {
            return '';
        }

        return '# '.(min($this->sel, $count - 1) + 1).'/'.$count;
    }

    private function modeString(\App\Repo\Domain\Git\TreeEntry $entry): string
    {
        $oct = (int) octdec($entry->mode);
        $perm = $oct & 0o777;
        if ($entry->isDirectory()) {
            $type = 'd';
            $perm = 0 !== $perm ? $perm : 0o777;
        } elseif (0o120000 === ($oct & 0o170000)) {
            $type = 'l';
        } else {
            $type = '-';
        }
        $rwx = '';
        foreach ([6, 3, 0] as $shift) {
            $p = ($perm >> $shift) & 7;
            $rwx .= (0 !== ($p & 4) ? 'r' : '-').(0 !== ($p & 2) ? 'w' : '-').(0 !== ($p & 1) ? 'x' : '-');
        }

        return $type.$rwx;
    }

    /**
     * @return list<string>
     */
    private function paintFile(int $cols, int $rows): array
    {
        $crumb = "\e[2m".Screen::fit('  '.$this->filePath, $cols)."\e[0m";
        $rows = max(1, $rows - 1);
        $count = \count($this->fileRaw);
        $gutter = max(3, \strlen((string) $count));
        $codeWidth = max(1, $cols - $gutter - 2);
        $top = $count > $rows ? max(0, min($this->cursorLine - intdiv($rows, 2), $count - $rows)) : 0;

        $rowsOut = [$crumb];
        for ($r = 0; $r < $rows; ++$r) {
            $idx = $top + $r;
            if ($idx >= $count) {
                $rowsOut[] = Screen::blank($cols);
                continue;
            }
            $raw = $this->fileRaw[$idx];
            $code = $this->ctx->renderer->isAvailable()
                ? $this->ctx->renderer->renderSnippet($this->filePath, $raw, $codeWidth)
                : $raw;
            $num = str_pad((string) ($idx + 1), $gutter, ' ', \STR_PAD_LEFT);
            if ($idx === $this->cursorLine) {
                $g = "\e[7m".$num.'▶'."\e[0m".' ';
            } else {
                $g = "\e[2m".$num.'│'."\e[0m".' ';
            }
            $rowsOut[] = Screen::fit($g.Screen::truncate($code, $codeWidth), $cols);
        }

        return $rowsOut;
    }

    /**
     * @return list<string>
     */
    private function paintRefs(int $cols, int $rows): array
    {
        $items = [];
        foreach ($this->refs as $loc) {
            $items[] = ['label' => $loc->path, 'meta' => ($loc->line + 1).':'.($loc->col + 1)];
        }
        $header = "\e[2m".Screen::fit('  '.\count($this->refs).' reference(s)', $cols)."\e[0m";

        return array_merge([$header], Screen::listView($items, $this->refSel, max(1, $rows - 1), $cols));
    }
}
