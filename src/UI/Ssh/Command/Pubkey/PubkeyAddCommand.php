<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Pubkey;

use App\Identity\Application\AddPublicKey;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PubkeyAddCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'pubkey:add';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly AddPublicKey $addPublicKey,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Add a public key to your account')
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
        ($this->addPublicKey)($actor, $actor->username(), $fullKey);

        // Go soft serve adds public keys silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
