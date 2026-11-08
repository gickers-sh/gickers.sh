<?php

declare(strict_types=1);

namespace App\UI\Tui\View\Repo\Commits;

use App\UI\Tui\Format\Diff;
use App\UI\Tui\View\Repo\RepoScreen;
use App\UI\Tui\View\Repo\RepoViews;
use App\UI\Tui\Widget\ScrollWidget;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Widget\LoaderWidget;

final class CommitDiffView extends RepoScreen
{
    private readonly ScrollWidget $scroll;
    private readonly LoaderWidget $loader;
    private bool $loaded = false;

    public function __construct(
        RepoViews $repo,
        private readonly string $sha,
        private readonly string $subject,
        int $tab = 2,
    ) {
        parent::__construct($repo);

        $this->loader = new LoaderWidget('Loading diff...');
        $this->loader->addStyleClass('indent');

        $this->scroll = new ScrollWidget();

        $body = new \Symfony\Component\Tui\Widget\ContainerWidget();
        $body->expandVertically(true);
        $body->add($this->loader);
        $body->add($this->scroll);

        $this->add($this->repo->frame($this, $body, $tab));
    }

    public function title(): string
    {
        return 'Commit '.substr($this->sha, 0, 7);
    }

    public function shortcuts(): array
    {
        return [
            'back' => ['q', Key::ESCAPE],
        ];
    }

    public function handleShortcut(string $action): bool
    {
        if ('back' === $action) {
            $this->repo->navigator()->pop();

            return true;
        }

        return false;
    }

    public function keyHints(): array
    {
        return [
            ['↑/↓', 'scroll'],
            ['esc', 'back'],
        ];
    }

    public function statusValue(): string
    {
        return substr($this->sha, 0, 7);
    }

    public function statusInfo(): string
    {
        return $this->subject;
    }

    protected function onActivate(): void
    {
        if (!$this->loaded) {
            $this->loadDiff();
        }
        $this->focus($this->scroll);
    }

    private function loadDiff(): void
    {
        $this->loaded = true;
        try {
            $process = $this->repo->views->reader->startCommitDiffProcess($this->repo->state->git, $this->sha);
            $this->repo->async()->watch($this, $process, function (Process $p): void {
                $raw = '';
                if ($p->isSuccessful() && '' !== $p->getOutput()) {
                    $diff = $this->repo->views->reader->parseDiffOutput($p->getOutput());
                    $raw = $diff->rawDiff;
                } else {
                    try {
                        $diff = $this->repo->views->reader->getDiff($this->repo->state->git, $this->sha);
                        $raw = $diff->rawDiff;
                    } catch (\Throwable) {
                        $this->repo->flash = 'Cannot load diff.';
                        $this->repo->navigator()->pop();

                        return;
                    }
                }

                $lines = preg_split('/\r\n|\r|\n/', rtrim($raw, "\n")) ?: [];
                $colored = Diff::colour($lines);

                $this->loader->addStyleClass('hidden');
                $this->scroll->setLines($colored);
                $this->repo->root()->refreshChrome();
            });
        } catch (\Throwable) {
            $this->repo->flash = 'Cannot load diff.';
            $this->repo->navigator()->pop();
        }
    }
}
