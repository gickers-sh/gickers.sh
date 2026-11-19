<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Settings;

use App\Access\Application\AccessGuard;
use App\Access\Application\ServerPolicy;
use App\Access\Domain\DeployPolicy;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SettingsDeployPolicyCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'settings:deploy-policy';
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
            ->setDescription('Set or get the server-wide deployment policy (admins-only, authorized, repo-admins)')
            ->addArgument('policy', InputArgument::OPTIONAL, 'Deploy policy (admins-only|authorized|repo-admins)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->accessGuard->assertServerAdmin($this->actor());

        $policyArg = $this->nullableStringArgument($input, 'policy');
        if (null === $policyArg) {
            $this->line($output, $this->serverPolicy->deployPolicy()->value);

            return Command::SUCCESS;
        }

        $policy = DeployPolicy::fromString($policyArg);
        $this->serverPolicy->setDeployPolicy($policy);

        return Command::SUCCESS;
    }
}
