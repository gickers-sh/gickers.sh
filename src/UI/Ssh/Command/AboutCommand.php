<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command;

use App\Access\Application\ServerPolicy;
use App\Repo\Application\Query\RepoQuery;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\AccessDenied;
use App\UI\Ssh\SshSession;
use App\UI\Tui\Render\TermstrapRenderer;
use App\UI\Tui\Render\Theme;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class AboutCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'about';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RepoQuery $repoQuery,
        private readonly BareRepositoryStorage $storage,
        private readonly GitRepositoryReader $gitReader,
        private readonly TermstrapRenderer $renderer,
        private readonly ServerPolicy $serverPolicy,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Display server about / README rendered with Termstrap')
            ->addArgument('reference', InputArgument::OPTIONAL, 'Git reference (branch, tag, or commit SHA)', 'HEAD')
            ->addOption('width', 'w', InputOption::VALUE_REQUIRED, 'Terminal width in columns (0 = auto-detect)', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = $this->actor();
        if (null === $actor && !$this->serverPolicy->anonAccess()->canRead()) {
            throw new AccessDenied('unauthorized');
        }

        $ref = $this->stringArgument($input, 'reference', 'HEAD');
        $width = $this->resolveTerminalWidth($input, $output, 100);

        $readme = null;
        try {
            $repo = $this->repoQuery->get($actor, '.soft-serve');
            $gitRepo = $this->storage->open($repo->name());
            $readme = $this->gitReader->readme($gitRepo, $ref);
        } catch (\Throwable) {
            $readme = null;
        }

        if (null !== $readme && '' !== trim($readme['content'])) {
            $rendered = $this->renderer->renderDocument($readme['path'], $readme['content'], $width);
            $this->writeRaw($output, $rendered.(str_ends_with($rendered, "\n") ? '' : "\n"));
        } else {
            $lines = [
                '',
                Theme::style('No readme found.', Theme::MUTED),
                '',
                Theme::style('Create a `.soft-serve` repository and add a README.md file to display readme.', Theme::MUTED),
                '',
            ];
            $this->writeRaw($output, implode("\n", $lines));
        }

        return Command::SUCCESS;
    }
}
