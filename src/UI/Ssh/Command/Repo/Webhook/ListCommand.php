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
            $this->line($output, 'No webhooks found.');

            return Command::SUCCESS;
        }

        foreach ($webhooks as $h) {
            $output->write(sprintf("#%-4d  %-40s  %-10s\n", $h->id(), $h->url(), $h->isActive() ? 'active' : 'inactive'));
        }

        return Command::SUCCESS;
    }
}
