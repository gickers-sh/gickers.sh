<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Wiki;

use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use App\Wiki\Application\Query\WikiQuery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class WikiSearchCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'wiki:search';
    }

    public static function sshAliases(): array
    {
        return ['wiki:grep', 'wiki:find'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly WikiQuery $wikiQuery,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Search wiki pages by keyword')
            ->addArgument('query', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Search query');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $words */
        $words = (array) $input->getArgument('query');
        $query = trim(implode(' ', $words));

        $hits = $this->wikiQuery->search($query);

        if ($this->isDecorated($output)) {
            $header = sprintf('%-20s %-15s %-25s %s', 'SLUG', 'CATEGORY', 'TITLE', 'SNIPPET');
            $this->line($output, \App\UI\Tui\Render\Theme::style($header, \App\UI\Tui\Render\Theme::BLUE, bold: true, underline: true));
            foreach ($hits as $hit) {
                $page = $hit['page'];
                $slugCol = \App\UI\Tui\Render\Theme::style(sprintf('%-20s', $page->slugString()), \App\UI\Tui\Render\Theme::CYAN, bold: true);
                $catCol = \App\UI\Tui\Render\Theme::style(sprintf('%-15s', $page->category()), \App\UI\Tui\Render\Theme::TEAL);
                $titleCol = \App\UI\Tui\Render\Theme::style(sprintf('%-25s', $page->title()), \App\UI\Tui\Render\Theme::FG, bold: true);
                $snippetCol = \App\UI\Tui\Render\Theme::style($hit['snippet'], \App\UI\Tui\Render\Theme::FG_DIM);
                $this->line($output, sprintf('%s %s %s %s', $slugCol, $catCol, $titleCol, $snippetCol));
            }
        } else {
            foreach ($hits as $hit) {
                $page = $hit['page'];
                $this->line($output, sprintf(
                    "%s\t%s\t%s\t%s",
                    $page->slugString(),
                    $page->category(),
                    $page->title(),
                    $hit['snippet'],
                ));
            }
        }

        return Command::SUCCESS;
    }
}
