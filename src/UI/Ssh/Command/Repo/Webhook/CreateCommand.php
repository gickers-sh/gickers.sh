<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Webhook;

use App\UI\Ssh\Command\AbstractSshCommand;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\SshSession;
use App\Webhook\Application\CreateWebhook;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CreateCommand extends AbstractSshCommand
{
    public function __construct(
        SshSession $sshSession,
        private readonly CreateWebhook $createWebhook,
    ) {
        parent::__construct($sshSession);
    }

    public static function sshName(): string
    {
        return 'repo:webhook:create';
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Create a repository webhook')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('url', InputArgument::REQUIRED, 'Webhook destination URL')
            ->addOption('secret', 's', InputOption::VALUE_REQUIRED, 'Secret to sign the webhook payload', '')
            ->addOption('events', 'e', InputOption::VALUE_REQUIRED, 'Comma-separated list of events to trigger the webhook', '')
            ->addOption('content-type', 'c', InputOption::VALUE_REQUIRED, 'Content type of payload (json or form)', 'json')
            ->addOption('active', 'a', InputOption::VALUE_OPTIONAL, 'Whether the webhook is active', 'true');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoName = $this->stringArgument($input, 'repository');
        $url = $this->stringArgument($input, 'url');
        $secret = $this->stringOption($input, 'secret');
        $contentTypeStr = $this->stringOption($input, 'content-type', 'json');
        $eventsStr = $this->stringOption($input, 'events');

        $events = '' !== trim($eventsStr)
            ? array_values(array_filter(array_map('trim', explode(',', $eventsStr))))
            : [];

        $rawActive = $input->getOption('active');
        if (null === $rawActive || true === $rawActive) {
            $active = true;
        } elseif (false === $rawActive) {
            $active = false;
        } else {
            $parsedActive = filter_var($rawActive, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
            if (null === $parsedActive) {
                throw new InvalidInput(sprintf('invalid boolean value "%s"', is_scalar($rawActive) ? (string) $rawActive : ''));
            }
            $active = $parsedActive;
        }

        $webhook = ($this->createWebhook)(
            actor: $this->actor(),
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
