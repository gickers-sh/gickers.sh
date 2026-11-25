<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Token;

use App\Identity\Application\RevokeAccessToken;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class TokenDeleteCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'token:delete';
    }

    public static function sshAliases(): array
    {
        return ['token:del', 'token:remove', 'token:rm'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RevokeAccessToken $revokeAccessToken,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Delete an access token')
            ->addArgument('id', InputArgument::REQUIRED, 'Token ID or name to revoke');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $idRaw = trim($this->stringArgument($input, 'id'));
        if ('' === $idRaw) {
            throw new InvalidInput('Missing token ID or name');
        }

        ($this->revokeAccessToken)($this->actor(), $idRaw);

        // Go soft serve outputs confirmation to stderr
        $this->errLine($output, 'Access token deleted');

        return Command::SUCCESS;
    }
}
