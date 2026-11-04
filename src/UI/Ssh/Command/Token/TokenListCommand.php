<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Token;

use App\Identity\Domain\AccessTokenStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class TokenListCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'token:list';
    }

    public static function sshAliases(): array
    {
        return ['token:ls'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly AccessTokenStore $accessTokenStore,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this->setDescription('List access tokens');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = $this->actor();
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $tokens = $this->accessTokenStore->forUser($actor);
        if ([] === $tokens) {
            $this->line($output, 'No tokens found');

            return Command::SUCCESS;
        }

        $now = $this->clock->now();
        $this->line($output, sprintf('%-5s %-20s %-25s %s', 'ID', 'Name', 'Created At', 'Expires In'));

        foreach ($tokens as $token) {
            $expiresAtStr = '-';
            $exp = $token->expiresAt();
            if (null !== $exp) {
                $expiresAtStr = $now > $exp ? 'expired' : $exp->format(\DateTimeInterface::ATOM);
            }

            $this->line($output, sprintf(
                '%-5d %-20s %-25s %s',
                $token->id(),
                $token->name(),
                $token->createdAt()->format(\DateTimeInterface::ATOM),
                $expiresAtStr
            ));
        }

        return Command::SUCCESS;
    }
}
