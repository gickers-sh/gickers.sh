<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Webhook;

use App\UI\Ssh\SshSession;
use App\Webhook\Application\Query\WebhookQuery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ListCommand extends Command
{
    public function __construct(
        private readonly SshSession $sshSession,
        private readonly WebhookQuery $webhookQuery,
    ) {
        parent::__construct();
    }

    public static function sshName(): string
    {
        return 'repo:webhook:list';
    }

    protected function configure(): void
    {
        $this
            ->setName(self::sshName())
            ->setDescription('List repository webhooks')
            ->setAliases(['repo:webhook:ls', 'repo:webhooks:list', 'repo:webhooks:ls'])
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoArg = $input->getArgument('repository');
        $repoName = is_string($repoArg) ? $repoArg : '';
        $webhooks = $this->webhookQuery->list($this->sshSession->user(), $repoName);

        if ([] === $webhooks) {
            $output->writeln('No webhooks found.');

            return Command::SUCCESS;
        }

        foreach ($webhooks as $h) {
            $output->write(sprintf("#%-4d  %-40s  %-10s\n", $h->id(), $h->url(), $h->isActive() ? 'active' : 'inactive'));
        }

        return Command::SUCCESS;
    }
}
