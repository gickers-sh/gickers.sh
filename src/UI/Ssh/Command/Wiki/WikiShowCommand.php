<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Wiki;

use App\Access\Application\ServerPolicy;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\NotFound;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use App\UI\Tui\Render\TermstrapRenderer;
use App\Wiki\Application\Query\WikiQuery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

final class WikiShowCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'wiki:show';
    }

    public static function sshAliases(): array
    {
        return ['wiki:view', 'wiki:get', 'wiki:cat', 'wiki:read'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly WikiQuery $wikiQuery,
        private readonly TermstrapRenderer $renderer,
        private readonly ServerPolicy $serverPolicy,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Display a wiki page')
            ->addArgument('slug', InputArgument::REQUIRED, 'Wiki page slug')
            ->addOption('raw', 'r', InputOption::VALUE_NONE, 'Print raw Markdown instead of terminal-rendered output')
            ->addOption('width', 'w', InputOption::VALUE_REQUIRED, 'Terminal width in columns (0 = auto-detect)', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = $this->actor();
        if (null === $actor && !$this->serverPolicy->anonAccess()->canRead()) {
            throw new AccessDenied('unauthorized');
        }
        $slug = $this->stringArgument($input, 'slug');
        $page = $this->wikiQuery->find($slug);
        if (null === $page) {
            throw new NotFound('wiki page not found');
        }

        $content = $page->content();
        if ((bool) $input->getOption('raw')) {
            $this->writeRaw($output, $content.(str_ends_with($content, "\n") ? '' : "\n"));

            return Command::SUCCESS;
        }

        $width = $this->resolveTerminalWidth($input, $output, 100);

        $rendered = $this->renderer->renderDocument($page->slugString().'.md', $content, $width);
        $this->writeRaw($output, $rendered.(str_ends_with($rendered, "\n") ? '' : "\n"));

        return Command::SUCCESS;
    }
}
