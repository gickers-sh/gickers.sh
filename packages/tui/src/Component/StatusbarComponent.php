<?php

declare(strict_types=1);

namespace CodesSh\Tui\Component;

use CodesSh\Tui\Event\InputEvent;
use CodesSh\Tui\Event\MouseMsg;
use CodesSh\Tui\Style\Ansi;
use CodesSh\Tui\Style\TokyoNight;
use CodesSh\Tui\Style\ZoneManager;

final class StatusbarComponent implements ComponentInterface
{
    private string $repoName;
    private string $branch;
    private string $info;
    private string $scrollProgress; // e.g. "≡ 42%" or "≡ TOP"
    private bool $showHelpPrompt;

    public function __construct(
        string $repoName = '',
        string $branch = 'main',
        string $scrollProgress = '≡ 0%',
        string $info = '',
        bool $showHelpPrompt = true,
    ) {
        $this->repoName = $repoName;
        $this->branch = $branch;
        $this->scrollProgress = $scrollProgress;
        $this->info = $info;
        $this->showHelpPrompt = $showHelpPrompt;
    }

    public function init(): void
    {
    }

    public function setRepoName(string $repoName): void
    {
        $this->repoName = $repoName;
    }

    public function setBranch(string $branch): void
    {
        $this->branch = $branch;
    }

    public function setScrollProgress(string $progress): void
    {
        $this->scrollProgress = $progress;
    }

    public function setInfo(string $info): void
    {
        $this->info = $info;
    }

    public function setShowHelpPrompt(bool $show): void
    {
        $this->showHelpPrompt = $show;
    }

    public function isHelpClicked(MouseMsg $event): bool
    {
        return $this->showHelpPrompt && $event->isLeftClick() && ZoneManager::inBounds('repo-help', $event);
    }

    public function setStatus(string $repoName, string $branch, string $info = '', string $scrollProgress = ''): void
    {
        $this->repoName = $repoName;
        $this->branch = $branch;
        $this->info = $info;
        if ('' !== $scrollProgress) {
            $this->scrollProgress = $scrollProgress;
        }
    }

    public function update(InputEvent $event): ?string
    {
        if ($event instanceof MouseMsg && $this->isHelpClicked($event)) {
            return 'help';
        }

        return null;
    }

    public function view(int $width, int $height): string
    {
        $reset = TokyoNight::reset();

        // Segment styles (matching Go styles.go: StatusBarKey/Value/Info/Branch/Help).
        $keyBg = TokyoNight::hexToAnsi(TokyoNight::STATUS_BAR_KEY_BG, true);
        $keyFg = TokyoNight::hexToAnsi(TokyoNight::STATUS_BAR_KEY_FG);
        $valBg = TokyoNight::hexToAnsi(TokyoNight::STATUS_BAR_VALUE_BG, true);
        $valFg = TokyoNight::hexToAnsi(TokyoNight::STATUS_BAR_VALUE_FG);
        $infoBg = TokyoNight::hexToAnsi(TokyoNight::STATUS_BAR_INFO_BG, true);
        $infoFg = TokyoNight::hexToAnsi(TokyoNight::STATUS_BAR_INFO_FG);
        $branchBg = TokyoNight::hexToAnsi(TokyoNight::STATUS_BAR_BRANCH_BG, true);
        $branchFg = TokyoNight::hexToAnsi(TokyoNight::STATUS_BAR_BRANCH_FG);
        $helpBg = TokyoNight::hexToAnsi(TokyoNight::STATUS_BAR_HELP_BG, true);
        $helpFg = TokyoNight::hexToAnsi(TokyoNight::STATUS_BAR_HELP_FG);

        // A pill segment: single-space horizontal padding (Go Padding(0, 1)).
        $pill = static fn (string $text, string $bg, string $fg): string => $bg.$fg.' '.$text.' '.$reset;

        // Order matches Go statusbar.View(): key, value (filler), info, branch, help.
        $key = '' !== $this->repoName ? $pill($this->repoName, $keyBg, $keyFg) : '';

        $info = '' !== $this->scrollProgress ? $pill($this->scrollProgress, $infoBg, $infoFg) : '';

        $branchText = '' !== $this->branch ? '* '.$this->branch : '';
        $branch = '' !== $branchText ? $pill($branchText, $branchBg, $branchFg) : '';

        $help = '';
        if ($this->showHelpPrompt) {
            $help = ZoneManager::mark('repo-help', $pill('? Help', $helpBg, $helpFg));
        }

        // Value segment fills the remaining width between key and the right pills.
        $used = Ansi::width($key) + Ansi::width($info) + Ansi::width($branch) + Ansi::width($help);
        $valWidth = max(0, $width - $used);
        $valInner = '' !== $this->info ? (' '.Ansi::truncate($this->info, max(0, $valWidth - 2)).' ') : '';
        $valInner .= str_repeat(' ', max(0, $valWidth - Ansi::width($valInner)));
        $value = $valBg.$valFg.$valInner.$reset;

        return $key.$value.$info.$branch.$help;
    }
}
