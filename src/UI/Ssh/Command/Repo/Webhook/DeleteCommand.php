<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Webhook;

use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use App\Webhook\Application\DeleteWebhook;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DeleteCommand extends AbstractSshCommand
{
    public function __construct(
        SshSession $sshSession,
        private readonly DeleteWebhook $deleteWebhook,
    ) {
        parent::__construct($sshSession);
    }

    public static function sshName(): string
    {
        return 'repo:webhook:delete';
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Delete a repository webhook')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('id', InputArgument::REQUIRED, 'Webhook ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoName = $this->stringArgument($input, 'repository');
        $rawId = $this->stringArgument($input, 'id');
        if (!ctype_digit($rawId)) {
            throw new InvalidInput(sprintf("invalid webhook ID '%s'", $rawId));
        }
        $id = (int) $rawId;

        ($this->deleteWebhook)(
            actor: $this->actor(),
            repoName: $repoName,
            id: $id,
        );

        $output->write(sprintf("Deleted webhook %d\n", $id));

        return Command::SUCCESS;
    }
}
