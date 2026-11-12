<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Settings;

use App\Access\Application\AccessGuard;
use App\Access\Application\ServerPolicy;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SettingsAllowSignupCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'settings:allow-signup';
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
            ->setDescription('Set or get whether unregistered keys may self-register with `signup`')
            ->addArgument('allow', InputArgument::OPTIONAL, 'Boolean flag (true|false)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->accessGuard->assertServerAdmin($this->actor());

        $allowArg = $this->nullableStringArgument($input, 'allow');
        if (null === $allowArg) {
            $this->line($output, $this->serverPolicy->allowSignup() ? 'true' : 'false');

            return Command::SUCCESS;
        }

        $allow = filter_var($allowArg, \FILTER_VALIDATE_BOOLEAN);
        $this->serverPolicy->setAllowSignup($allow);

        return Command::SUCCESS;
    }
}
