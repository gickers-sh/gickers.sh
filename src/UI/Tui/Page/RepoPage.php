<?php

declare(strict_types=1);

namespace App\UI\Tui\Page;

use App\CodeIntel\Application\Query\CodeIntelQuery;
use App\Identity\Domain\Model\User;
use App\PullRequest\Application\MergePullRequest;
use App\PullRequest\Application\Query\PullRequestQuery;
use App\Repo\Application\UpdateRepoSettings;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\Model\Repo;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\UI\Tui\Input\Keys;
use App\UI\Tui\Page\Repo\CommitsTab;
use App\UI\Tui\Page\Repo\FilesTab;
use App\UI\Tui\Page\Repo\ProfileTab;
use App\UI\Tui\Page\Repo\PullsTab;
use App\UI\Tui\Page\Repo\ReadmeTab;
use App\UI\Tui\Page\Repo\RefListTab;
use App\UI\Tui\Page\Repo\RepoContext;
use App\UI\Tui\Page\Repo\RepoTab;
use App\UI\Tui\Page\Repo\SettingsTab;
use App\UI\Tui\Render\Screen;
use App\UI\Tui\Render\TermstrapRenderer;

/**
 * Single-repository browser: a tab bar over per-tab controllers (Readme, Files,
 * Commits, Branches, Tags, Pull Requests, Settings). Each {@see RepoTab} owns
 * its own keyboard handling, overlays and painting; this page only draws the
 * chrome, switches tabs and routes keys to the active tab. Shared repository
 * state and memoised git data live in {@see RepoContext}. Keyboard-only.
 *
 * Mirrors Go `pkg/ui/pages/repo`. Works both on a stored {@see Repo} (feed
 * navigation) and a bare/non-bare {@see GitRepository} opened by path (soft:browse),
 * in which case `$db` is null and settings/pull requests are read-only.
 */
final class RepoPage extends AbstractPage
{
    /** @var list<string> */
    private const TABS = ['Readme', 'Files', 'Commits', 'Branches', 'Tags', 'Pull Requests', 'Profile', 'Settings'];

    private readonly RepoContext $ctx;
    /** @var list<RepoTab> */
    private readonly array $tabs;
    private int $tab = 0;
    private bool $helpOpen = false;

    public function __construct(
        GitRepository $git,
        ?Repo $db,
        ?User $actor,
        GitRepositoryReader $reader,
        TermstrapRenderer $renderer,
        CodeIntelQuery $codeIntel,
        UpdateRepoSettings $updateSettings,
        ?PullRequestQuery $pullRequestQuery = null,
        ?MergePullRequest $mergePullRequest = null,
        string $sshPublicUrl = '',
    ) {
        $this->ctx = new RepoContext(
            $git,
            $db,
            $actor,
            $reader,
            $renderer,
            $codeIntel,
            $updateSettings,
            $pullRequestQuery,
            $mergePullRequest,
            $sshPublicUrl,
        );
        $this->tabs = [
            new ReadmeTab($this->ctx),
            new FilesTab($this->ctx),
            new CommitsTab($this->ctx),
            new RefListTab($this->ctx, true),
            new RefListTab($this->ctx, false),
            new PullsTab($this->ctx),
            new ProfileTab($this->ctx),
            new SettingsTab($this->ctx),
        ];
    }

    public function handleKey(string $seq): bool
    {
        $this->ctx->flash = null;

        $tok = Keys::normalize($seq);
        $txt = Keys::text($seq);

        if ($this->helpOpen) {
            if (Keys::CTRL_C === $tok) {
                $this->host->quit();
            } else {
                $this->helpOpen = false;
            }

            return true;
        }

        $active = $this->tabs[$this->tab];
        if ($active->capturesInput()) {
            return $active->handleKey($seq);
        }

        if (Keys::CTRL_C === $tok) {
            $this->host->quit();

            return true;
        }
        if (Keys::ESCAPE === $tok || 'q' === $txt) {
            $this->host->navigateToSelection();

            return true;
        }
        if ('?' === $txt) {
            $this->helpOpen = true;

            return true;
        }
        if (Keys::TAB === $tok) {
            $this->switchTab(($this->tab + 1) % \count(self::TABS));

            return true;
        }
        if (Keys::BACKTAB === $tok) {
            $this->switchTab(($this->tab + \count(self::TABS) - 1) % \count(self::TABS));

            return true;
        }
        if (null !== $txt && ctype_digit($txt) && '1' <= $txt && $txt <= '8') {
            $this->switchTab((int) $txt - 1);

            return true;
        }

        return $active->handleKey($seq);
    }

    private function switchTab(int $tab): void
    {
        $this->tab = $tab;
        $this->tabs[$tab]->onActivate();
    }

    protected function headerLines(int $cols): array
    {
        $name = $this->ctx->name.($this->ctx->db?->isPrivate() ? ' 🔒' : '');
        $desc = $this->ctx->db?->description() ?? '';

        return Screen::repoHeader($name, $desc, $this->ctx->cloneCommand(), $cols);
    }

    protected function tabBarLines(int $cols): array
    {
        return [Screen::tabBar(self::TABS, $this->tab, $cols), ''];
    }

    protected function footerLines(int $cols): array
    {
        $active = $this->tabs[$this->tab];
        $branch = '* '.$this->ctx->ref;
        if (null !== $this->ctx->flash) {
            $status = "\e[7m".Screen::fit(' '.$this->ctx->flash, $cols)."\e[0m";
        } else {
            $status = Screen::statusBar($cols, $this->ctx->name, $active->statusValue(), $active->statusInfo(), $branch);
        }

        if (!$this->helpOpen) {
            return [$status];
        }

        $help = Screen::helpGrid($this->helpColumns(), $cols);

        return array_merge([$status, ''], $help);
    }

    /**
     * @return list<list<array{0: string, 1: string}>>
     */
    private function helpColumns(): array
    {
        return [
            [['esc', 'back to menu'], ['tab', 'switch tab'], ['1–8', 'jump to tab']],
            [['↑/k', 'up'], ['↓/j', 'down'], ['g/G', 'top / bottom']],
            [['enter', 'open'], ['f/b', 'page down / up'], ['?', 'toggle help']],
        ];
    }

    protected function paintBody(int $cols, int $rows): array
    {
        return $this->tabs[$this->tab]->paintBody($cols, $rows);
    }
}
