<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Pubkey;

use App\Identity\Application\RemovePublicKey;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PubkeyRemoveCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'pubkey:remove';
    }

    public static function sshAliases(): array
    {
        return ['pubkey:rm', 'pubkey:del'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RemovePublicKey $removePublicKey,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Remove a public key from your account')
            ->addArgument('key', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Public key string');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = $this->actor();
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        /** @var list<string> $keyArgs */
        $keyArgs = (array) $input->getArgument('key');
        if ([] === $keyArgs) {
            throw new InvalidInput('Missing public key argument');
        }

        $fullKey = implode(' ', $keyArgs);
        ($this->removePublicKey)($actor, $actor->username(), $fullKey);

        // Go soft serve removes public keys silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
