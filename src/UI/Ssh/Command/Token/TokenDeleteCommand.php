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
            ->addArgument('id', InputArgument::REQUIRED, 'Token ID to revoke');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $idRaw = $this->stringArgument($input, 'id');
        if (!is_numeric($idRaw)) {
            throw new InvalidInput('Invalid token ID');
        }

        ($this->revokeAccessToken)($this->actor(), (int) $idRaw);

        // Go soft serve outputs confirmation to stderr
        $this->errLine($output, 'Access token deleted');

        return Command::SUCCESS;
    }
}
