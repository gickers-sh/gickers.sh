<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Server;

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

final class ServerSettingsAnonAccessCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'server:settings:anon-access';
    }

    /**
     * @return list<string>
     */
    public static function sshAliases(): array
    {
        return [
            'settings:anon-access',
            'host:settings:anon-access',
        ];
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
            $str = $this->serverPolicy->anonAccess()->toString();
            $this->line($output, $this->style($output, $str, \App\UI\Tui\Render\Theme::TEAL, bold: true));

            return Command::SUCCESS;
        }

        $level = AccessLevel::tryFromString($levelArg);
        if (null === $level) {
            throw new InvalidInput(sprintf('invalid access level: %s', $levelArg));
        }

        $this->serverPolicy->setAnonAccess($level);

        return Command::SUCCESS;
    }
}
