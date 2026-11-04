<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Webhook;

use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\SshSession;
use App\Webhook\Application\DeleteWebhook;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DeleteCommand extends Command
{
    public function __construct(
        private readonly SshSession $sshSession,
        private readonly DeleteWebhook $deleteWebhook,
    ) {
        parent::__construct();
    }

    public static function sshName(): string
    {
        return 'repo:webhook:delete';
    }

    protected function configure(): void
    {
        $this
            ->setName(self::sshName())
            ->setDescription('Delete a repository webhook')
            ->setAliases([
                'repo:webhook:remove',
                'repo:webhook:rm',
                'repo:webhook:del',
                'repo:webhooks:delete',
                'repo:webhooks:remove',
                'repo:webhooks:rm',
                'repo:webhooks:del',
            ])
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('id', InputArgument::REQUIRED, 'Webhook ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoArg = $input->getArgument('repository');
        $repoName = is_string($repoArg) ? $repoArg : '';
        $idArg = $input->getArgument('id');
        $rawId = is_string($idArg) ? $idArg : '';
        if (!ctype_digit($rawId)) {
            throw new InvalidInput(sprintf("invalid webhook ID '%s'", $rawId));
        }
        $id = (int) $rawId;

        ($this->deleteWebhook)(
            actor: $this->sshSession->user(),
            repoName: $repoName,
            id: $id,
        );

        $output->write(sprintf("Deleted webhook %d\n", $id));

        return Command::SUCCESS;
    }
}
