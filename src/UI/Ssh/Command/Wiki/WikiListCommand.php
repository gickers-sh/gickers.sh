<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Wiki;

use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use App\Wiki\Application\Query\WikiQuery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class WikiListCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'wiki:list';
    }

    public static function sshAliases(): array
    {
        return ['wiki:ls'];
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
            ->setDescription('List all wiki pages')
            ->addOption('category', 'c', InputOption::VALUE_REQUIRED, 'Filter pages by category');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $category = $this->nullableStringOption($input, 'category');
        $pages = $this->wikiQuery->all($category);

        if ($this->isDecorated($output)) {
            $header = sprintf('%-20s %-15s %-30s %s', 'SLUG', 'CATEGORY', 'TITLE', 'UPDATED AT');
            $this->line($output, \App\UI\Tui\Render\Theme::style($header, \App\UI\Tui\Render\Theme::BLUE, bold: true, underline: true));
            foreach ($pages as $page) {
                $slugCol = \App\UI\Tui\Render\Theme::style(sprintf('%-20s', $page->slugString()), \App\UI\Tui\Render\Theme::CYAN, bold: true);
                $catCol = \App\UI\Tui\Render\Theme::style(sprintf('%-15s', $page->category()), \App\UI\Tui\Render\Theme::TEAL);
                $titleCol = \App\UI\Tui\Render\Theme::style(sprintf('%-30s', $page->title()), \App\UI\Tui\Render\Theme::FG);
                $dateCol = \App\UI\Tui\Render\Theme::style($page->updatedAt()->format('Y-m-d H:i:s'), \App\UI\Tui\Render\Theme::MUTED);
                $this->line($output, sprintf('%s %s %s %s', $slugCol, $catCol, $titleCol, $dateCol));
            }
        } else {
            foreach ($pages as $page) {
                $this->line($output, sprintf(
                    "%s\t%s\t%s\t%s",
                    $page->slugString(),
                    $page->category(),
                    $page->title(),
                    $page->updatedAt()->format('Y-m-d H:i:s'),
                ));
            }
        }

        return Command::SUCCESS;
    }
}
