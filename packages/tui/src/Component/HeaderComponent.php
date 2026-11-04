<?php

declare(strict_types=1);

namespace CodesSh\Tui\Component;

use CodesSh\Tui\Event\InputEvent;
use CodesSh\Tui\Style\Ansi;
use CodesSh\Tui\Style\TokyoNight;

final class HeaderComponent implements ComponentInterface
{
    private string $title;
    /** @var array<string, string> badge label => color */
    private array $badges = [];
    private string $subtitle;
    private string $projectName = '';
    private string $cloneUrl = '';
    private ?string $forkedFrom = null;
    private bool $isRepoMode = false;

    /**
     * @param array<string, string> $badges Map of badge text => color (e.g. ['public' => TokyoNight::SUCCESS])
     */
    public function __construct(string $title = '', array $badges = [], string $subtitle = '')
    {
        $this->title = $title;
        $this->badges = $badges;
        $this->subtitle = $subtitle;
    }

    public function init(): void
    {
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function setSubtitle(string $subtitle): void
    {
        $this->subtitle = $subtitle;
    }

    public function addBadge(string $text, string $color): void
    {
        $this->badges[$text] = $color;
    }

    public function clearBadges(): void
    {
        $this->badges = [];
    }

    public function setRepoInfo(string $repoName, string $projectName = '', string $description = '', string $cloneUrl = '', ?string $forkedFrom = null): void
    {
        $this->isRepoMode = true;
        $this->title = $repoName;
        $this->projectName = $projectName;
        $this->subtitle = $description;
        $this->cloneUrl = $cloneUrl;
        $this->forkedFrom = $forkedFrom;
    }

    public function update(InputEvent $event): ?string
    {
        return null;
    }

    public function view(int $width, int $height): string
    {
        $reset = TokyoNight::reset();

        if ($this->isRepoMode) {
            return $this->viewRepoHeader($width);
        }

        // ServerName header mode (used in Selection and Admin pages)
        if (empty($this->badges) && '' === $this->subtitle) {
            $bg = TokyoNight::hexToAnsi(TokyoNight::SERVER_NAME_BG, true);
            $fg = TokyoNight::hexToAnsi(TokyoNight::SERVER_NAME_FG);

            return ' '."\x1b[1m".$bg.$fg.' '.trim($this->title).' '.$reset;
        }

        // Extended generic header
        $titleColor = TokyoNight::hexToAnsi(TokyoNight::CYAN);
        $titleText = "\x1b[1m".$titleColor.$this->title.$reset;

        $badgeStr = '';
        foreach ($this->badges as $label => $color) {
            $badgeBg = TokyoNight::hexToAnsi($color, true);
            $badgeFg = TokyoNight::hexToAnsi(TokyoNight::BG);
            $badgeStr .= ' '."\x1b[1m".$badgeBg.$badgeFg.' '.$label.' '.$reset;
        }

        $subtitleStr = '';
        if ('' !== $this->subtitle) {
            $mutedColor = TokyoNight::hexToAnsi(TokyoNight::MUTED);
            $subtitleStr = ' '.$mutedColor.$this->subtitle.$reset;
        }

        $left = $titleText.$badgeStr.$subtitleStr;
        $leftWidth = Ansi::width($left);
        $pad = max(0, $width - $leftWidth);

        return $left.str_repeat(' ', $pad);
    }

    private function viewRepoHeader(int $width): string
    {
        $reset = TokyoNight::reset();
        $nameColor = TokyoNight::hexToAnsi(TokyoNight::REPO_HEADER_NAME);
        $repoNameColor = TokyoNight::hexToAnsi(TokyoNight::REPO_HEADER_REPO_NAME);
        $descColor = TokyoNight::hexToAnsi(TokyoNight::REPO_HEADER_DESC);
        $urlColor = TokyoNight::hexToAnsi(TokyoNight::URL_STYLE);

        $pname = $this->projectName;
        $rname = $this->title;

        if ('' !== $pname && $pname !== $rname) {
            $headerLeft = "\x1b[1m".$nameColor.$pname.$reset.' '."\x1b[2m".$repoNameColor.$rname.$reset;
            $leftPlain = $pname.' '.$rname;
        } else {
            $headerLeft = "\x1b[1m".$nameColor.$rname.$reset;
            $leftPlain = $rname;
        }

        $leftW = Ansi::width($leftPlain);
        $urlPlain = $this->cloneUrl;
        $urlW = Ansi::width($urlPlain);

        // Align clone URL to the right
        $lines = [];
        if ('' !== $urlPlain && ($width - $leftW - 1) > 0) {
            $availUrlW = max(5, $width - $leftW - 1);
            if ($urlW > $availUrlW) {
                $urlPlain = Ansi::truncate($urlPlain, $availUrlW);
                $urlW = Ansi::width($urlPlain);
            }
            $pad = max(1, $width - $leftW - $urlW);
            $topRow = $headerLeft.str_repeat(' ', $pad).$urlColor.$urlPlain.$reset;
        } else {
            $topRow = $headerLeft;
        }
        $lines[] = $topRow;

        if ('' !== $this->subtitle) {
            $desc = trim($this->subtitle);
            $descLines = explode("\n", $desc);
            if (count($descLines) > 5) {
                $descLines = array_slice($descLines, 0, 5);
            }
            foreach ($descLines as $dl) {
                $dl = trim($dl);
                if ('' !== $dl) {
                    $lines[] = "\x1b[3m".$descColor.Ansi::truncate($dl, $width).$reset;
                }
            }
        }

        if (null !== $this->forkedFrom && '' !== $this->forkedFrom) {
            $forkText = '⑂ forked from '.$this->forkedFrom;
            $lines[] = "\x1b[4m".$urlColor.Ansi::truncate($forkText, $width).$reset;
        }

        return implode("\n", $lines);
    }
}
