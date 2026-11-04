<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Webhook;

use App\UI\Ssh\SshSession;
use App\Webhook\Application\CreateWebhook;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CreateCommand extends Command
{
    public function __construct(
        private readonly SshSession $sshSession,
        private readonly CreateWebhook $createWebhook,
    ) {
        parent::__construct();
    }

    public static function sshName(): string
    {
        return 'repo:webhook:create';
    }

    protected function configure(): void
    {
        $this
            ->setName(self::sshName())
            ->setDescription('Create a repository webhook')
            ->setAliases(['repo:webhooks:create'])
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('url', InputArgument::REQUIRED, 'Webhook destination URL')
            ->addOption('secret', 's', InputOption::VALUE_REQUIRED, 'Secret to sign the webhook payload', '')
            ->addOption('events', 'e', InputOption::VALUE_REQUIRED, 'Comma-separated list of events to trigger the webhook', '')
            ->addOption('content-type', 'c', InputOption::VALUE_REQUIRED, 'Content type of payload (json or form)', 'json')
            ->addOption('active', 'a', InputOption::VALUE_NONE, 'Whether the webhook is active');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoArg = $input->getArgument('repository');
        $repoName = is_string($repoArg) ? $repoArg : '';
        $urlArg = $input->getArgument('url');
        $url = is_string($urlArg) ? $urlArg : '';
        $secOpt = $input->getOption('secret');
        $secret = is_string($secOpt) ? $secOpt : '';
        $ctOpt = $input->getOption('content-type');
        $contentTypeStr = is_string($ctOpt) ? $ctOpt : 'json';
        $evOpt = $input->getOption('events');
        $eventsStr = is_string($evOpt) ? $evOpt : '';

        $events = '' !== trim($eventsStr)
            ? array_values(array_filter(array_map('trim', explode(',', $eventsStr))))
            : [];

        // In Go, active is true by default. If flag -a is passed or defaulted:
        $active = true;

        $webhook = ($this->createWebhook)(
            actor: $this->sshSession->user(),
            repoName: $repoName,
            url: $url,
            contentType: $contentTypeStr,
            secret: $secret,
            events: $events,
            isActive: $active,
        );

        $output->write(sprintf("Created webhook %d for %s\n", $webhook->id(), $repoName));

        return Command::SUCCESS;
    }
}
