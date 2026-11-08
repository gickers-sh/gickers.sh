<?php

declare(strict_types=1);

namespace App\UI\Tui\Widget;

use App\UI\Tui\AsyncLoader;
use App\UI\Tui\Format\Ansi;
use App\UI\Tui\Render\TermstrapRenderer;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\LoaderWidget;

/**
 * A Termstrap-rendered document (README, Markdown file, PR description) in a
 * {@see ScrollWidget}, rendered asynchronously at the current width.
 *
 * While the source is loading ({@see showLoading()}) or being rendered, a
 * {@see LoaderWidget} replaces the text. Clicking an OSC 8 hyperlink calls the
 * {@see onLink()} callback (there is no browser in a TUI session).
 */
final class DocumentWidget extends ContainerWidget
{
    private readonly ScrollWidget $scroll;
    private readonly LoaderWidget $loader;
    private ?string $filename = null;
    private string $content = '';
    private string $message = 'Loading...';
    /** Rendered-lines cache key: filename|md5(content)|width. */
    private ?string $key = null;
    private ?string $pendingKey = null;
    private int $chromeColumns = 2 * Ansi::MARGIN;
    /** @var \Closure(string): void|null */
    private ?\Closure $onLink = null;

    public function __construct(
        private readonly TermstrapRenderer $renderer,
        private readonly AsyncLoader $async,
    ) {
        $this->loader = new LoaderWidget($this->message);
        $this->loader->addStyleClass('indent')->addStyleClass('hidden');
        $this->scroll = new ScrollWidget();
        $this->scroll->onClickLine(function (int $line, int $col, bool $nav): bool {
            $url = self::linkAt($this->scroll->lines()[$line] ?? '', $col);
            if (null === $url || null === $this->onLink) {
                return false;
            }
            ($this->onLink)($url);

            return true;
        });
        $this->add($this->loader);
        $this->add($this->scroll);
        $this->expandVertically(true);
    }

    public function scrollWidget(): ScrollWidget
    {
        return $this->scroll;
    }

    /**
     * Columns of the terminal not available to the document (page margins, borders).
     */
    public function setChromeColumns(int $columns): static
    {
        $this->chromeColumns = max(0, $columns);

        return $this;
    }

    /**
     * @param \Closure(string $url): void $fn
     */
    public function onLink(\Closure $fn): static
    {
        $this->onLink = $fn;

        return $this;
    }

    /**
     * Show the spinner with $message until {@see setDocument()} or {@see setLines()};
     * the same message is kept while the document renders.
     */
    public function showLoading(string $message): void
    {
        $this->message = $message;
        $this->filename = null;
        $this->key = null;
        $this->cancelRender();
        $this->setLoading(true);
    }

    public function isLoading(): bool
    {
        return $this->loader->isRunning();
    }

    public function setDocument(string $filename, string $content): void
    {
        if ($filename === $this->filename && $content === $this->content && null !== $this->key) {
            return;
        }
        $this->cancelRender();
        $this->filename = $filename;
        $this->content = $content;
        $this->key = null;
        $this->invalidate();
    }

    /**
     * Show pre-formatted lines as-is (no rendering).
     *
     * @param list<string> $lines
     */
    public function setLines(array $lines): void
    {
        $this->cancelRender();
        $this->filename = null;
        $this->key = null;
        $this->setLoading(false);
        $this->scroll->setLines($lines);
    }

    public function beforeRender(): void
    {
        $filename = $this->filename;
        if (null === $filename) {
            return;
        }
        $width = max(1, ($this->getContext()?->getTerminalColumns() ?? 80) - $this->chromeColumns);
        $key = $this->filename.'|'.md5($this->content).'|'.$width;
        if ($key === $this->key || $key === $this->pendingKey) {
            return;
        }
        $raw = self::rawLines($this->content);
        if (!$this->renderer->isAvailable()) {
            $this->key = $key;
            $this->setLoading(false);
            $this->scroll->setLines($raw);

            return;
        }
        $this->cancelRender();
        try {
            $process = $this->renderer->startRenderDocumentProcess($filename, $this->content, max(20, $width - 2));
        } catch (\Throwable) {
            $this->key = $key;
            $this->setLoading(false);
            $this->scroll->setLines($raw);

            return;
        }
        $this->pendingKey = $key;
        $this->setLoading(true);
        $this->async->watch($this, $process, function (Process $p) use ($key, $raw): void {
            if ($key !== $this->pendingKey) {
                return;
            }
            $this->pendingKey = null;
            $this->key = $key;
            $this->setLoading(false);
            $this->scroll->setLines($p->isSuccessful()
                ? (preg_split('/\r\n|\r|\n/', rtrim($p->getOutput(), "\n")) ?: [])
                : $raw);
        });
    }

    protected function onDetach(): void
    {
        $this->cancelRender();
    }

    /**
     * Find the OSC 8 hyperlink target under visible column $targetCol in a
     * rendered line, or null when no link covers it. Skips CSI/OSC escapes while
     * counting visible columns so the mapping matches what the terminal shows.
     */
    public static function linkAt(string $line, int $targetCol): ?string
    {
        $len = \strlen($line);
        $i = 0;
        $col = 0;
        $url = null;
        $start = 0;
        while ($i < $len) {
            $c = $line[$i];
            if ("\e" === $c && $i + 1 < $len) {
                $n = $line[$i + 1];
                if (']' === $n) {
                    $j = $i + 2;
                    $osc = '';
                    $end = null;
                    while ($j < $len) {
                        if ("\x07" === $line[$j]) {
                            $end = $j + 1;
                            break;
                        }
                        if ("\e" === $line[$j] && $j + 1 < $len && '\\' === $line[$j + 1]) {
                            $end = $j + 2;
                            break;
                        }
                        $osc .= $line[$j];
                        ++$j;
                    }
                    if (null === $end) {
                        break;
                    }
                    if (str_starts_with($osc, '8;')) {
                        $rest = substr($osc, 2);
                        $semi = strpos($rest, ';');
                        $uri = false === $semi ? '' : substr($rest, $semi + 1);
                        if ('' === $uri) {
                            if (null !== $url && $targetCol >= $start && $targetCol < $col) {
                                return $url;
                            }
                            $url = null;
                        } else {
                            $url = $uri;
                            $start = $col;
                        }
                    }
                    $i = $end;
                    continue;
                }
                if ('[' === $n) {
                    $j = $i + 2;
                    while ($j < $len && (\ord($line[$j]) < 0x40 || \ord($line[$j]) > 0x7E)) {
                        ++$j;
                    }
                    $i = $j + 1;
                    continue;
                }
                $i += 2;
                continue;
            }
            $o = \ord($c);
            $i += $o < 0x80 ? 1 : ($o < 0xE0 ? 2 : ($o < 0xF0 ? 3 : 4));
            ++$col;
        }
        if (null !== $url && $targetCol >= $start && $targetCol < $col) {
            return $url;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function rawLines(string $content): array
    {
        return preg_split('/\r\n|\r|\n/', rtrim($content, "\n")) ?: [];
    }

    private function cancelRender(): void
    {
        $this->pendingKey = null;
        $this->async->cancel($this);
    }

    private function setLoading(bool $loading): void
    {
        if ($loading) {
            $this->loader->setMessage($this->message);
            $this->loader->removeStyleClass('hidden');
            $this->scroll->addStyleClass('hidden');
            $this->loader->start();
        } else {
            $this->loader->stop();
            $this->loader->addStyleClass('hidden');
            $this->scroll->removeStyleClass('hidden');
        }
    }
}
