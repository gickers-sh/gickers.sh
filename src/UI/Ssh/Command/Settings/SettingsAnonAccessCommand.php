<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Settings;

use App\Access\Application\AccessGuard;
use App\Access\Application\ServerPolicy;
use App\Access\Domain\AccessLevel;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SettingsAnonAccessCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'settings:anon-access';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly ServerPolicy $serverPolicy,
        private readonly AccessGuard $accessGuard,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Set or get the default access level for anonymous users')
            ->addArgument('access_level', InputArgument::OPTIONAL, 'Access level string');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->accessGuard->assertServerAdmin($this->actor());

        $levelArg = $this->nullableStringArgument($input, 'access_level');
        if (null === $levelArg) {
            $this->line($output, $this->serverPolicy->anonAccess()->toString());

            return Command::SUCCESS;
        }

        $level = AccessLevel::tryFromString($levelArg);
        if (null === $level) {
            throw new InvalidInput(sprintf('invalid access level: %s', $levelArg));
        }

        $this->serverPolicy->setAnonAccess($level);

        // Go soft serve updates anon-access silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
