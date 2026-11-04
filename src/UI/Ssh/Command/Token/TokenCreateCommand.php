<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Token;

use App\Identity\Application\IssueAccessToken;
use App\Shared\Domain\DurationParser;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class TokenCreateCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'token:create';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly IssueAccessToken $issueAccessToken,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Create a new access token')
            ->addArgument('name', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Token name')
            ->addOption('expires-in', 'e', InputOption::VALUE_REQUIRED, 'Token expiration time (e.g. 1y, 3mo, 2w, 5d4h, 1h30m)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $nameParts */
        $nameParts = (array) $input->getArgument('name');
        if ([] === $nameParts) {
            throw new InvalidInput('Missing token name');
        }
        $name = implode(' ', $nameParts);

        $expiresIn = $input->getOption('expires-in');
        $expiresAt = null;
        $notice = 'Access token created';

        if (is_string($expiresIn) && '' !== trim($expiresIn)) {
            $seconds = DurationParser::toSeconds($expiresIn);
            if ($seconds > 0) {
                $expiresAt = $this->clock->now()->modify(sprintf('+%d seconds', $seconds));
                $notice .= sprintf(' (expires in %s)', trim($expiresIn));
            }
        }

        [, $secret] = ($this->issueAccessToken)(
            actor: $this->actor(),
            name: $name,
            expiresAt: $expiresAt,
        );

        // Go soft serve outputs notice to stderr and secret token to stdout
        $this->errLine($output, $notice);
        $this->line($output, $secret);

        return Command::SUCCESS;
    }
}
