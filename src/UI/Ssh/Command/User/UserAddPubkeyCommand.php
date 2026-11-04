<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\User;

use App\Access\Application\AccessGuard;
use App\Identity\Application\AddPublicKey;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class UserAddPubkeyCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'user:add-pubkey';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly AddPublicKey $addPublicKey,
        private readonly AccessGuard $accessGuard,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Add public key(s) to a user')
            ->addArgument('username', InputArgument::REQUIRED, 'Username')
            ->addArgument('key', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Public key string(s)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->accessGuard->assertServerAdmin($this->actor());

        $username = $this->stringArgument($input, 'username');
        /** @var list<string> $keyArgs */
        $keyArgs = (array) $input->getArgument('key');

        if ([] === $keyArgs) {
            throw new InvalidInput('Missing public key argument');
        }

        $fullKey = implode(' ', $keyArgs);
        ($this->addPublicKey)($this->actor(), $username, $fullKey);

        // Go soft serve adds public keys silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
