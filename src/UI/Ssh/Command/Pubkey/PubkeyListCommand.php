<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Pubkey;

use App\Shared\Domain\Exception\AccessDenied;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PubkeyListCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'pubkey:list';
    }

    public static function sshAliases(): array
    {
        return ['pubkey:ls'];
    }

    public function __construct(
        SshSession $sshSession,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this->setDescription('List your public keys');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = $this->actor();
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        foreach ($actor->publicKeys() as $key) {
            $this->line($output, $this->formatPublicKey($output, $key->authorizedKey()));
        }

        return Command::SUCCESS;
    }
}
