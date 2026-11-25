<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Webhook;

use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use App\Webhook\Application\Query\WebhookQuery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ListCommand extends AbstractSshCommand
{
    public function __construct(
        SshSession $sshSession,
        private readonly WebhookQuery $webhookQuery,
    ) {
        parent::__construct($sshSession);
    }

    public static function sshName(): string
    {
        return 'repo:webhook:list';
    }

    protected function configure(): void
    {
        $this
            ->setDescription('List repository webhooks')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoName = $this->stringArgument($input, 'repository');
        $webhooks = $this->webhookQuery->list($this->actor(), $repoName);

        if ([] === $webhooks) {
            $this->line($output, $this->formatMuted($output, 'No webhooks found.'));

            return Command::SUCCESS;
        }

        if ($this->isDecorated($output)) {
            $header = sprintf("#%-4s  %-40s  %-10s\n", 'ID', 'URL', 'STATUS');
            $output->write(\App\UI\Tui\Render\Theme::style($header, \App\UI\Tui\Render\Theme::BLUE, bold: true, underline: true));
        }

        foreach ($webhooks as $h) {
            if ($this->isDecorated($output)) {
                $idCol = \App\UI\Tui\Render\Theme::style(sprintf('#%-4d', $h->id()), \App\UI\Tui\Render\Theme::ORANGE);
                $urlCol = \App\UI\Tui\Render\Theme::style(sprintf('%-40s', $h->url()), \App\UI\Tui\Render\Theme::BLUE, underline: true);
                $statusCol = $h->isActive()
                    ? \App\UI\Tui\Render\Theme::style(sprintf('%-10s', 'active'), \App\UI\Tui\Render\Theme::GREEN, bold: true)
                    : \App\UI\Tui\Render\Theme::style(sprintf('%-10s', 'inactive'), \App\UI\Tui\Render\Theme::MUTED);
                $output->write(sprintf("%s  %s  %s\n", $idCol, $urlCol, $statusCol));
            } else {
                $output->write(sprintf("#%-4d  %-40s  %-10s\n", $h->id(), $h->url(), $h->isActive() ? 'active' : 'inactive'));
            }
        }

        return Command::SUCCESS;
    }
}
