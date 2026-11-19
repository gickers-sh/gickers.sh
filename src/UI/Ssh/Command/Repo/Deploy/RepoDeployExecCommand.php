<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Deploy;

use App\Deploy\Application\ExecDeployCommand;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeployExecCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:exec';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly ExecDeployCommand $execDeployCommand,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Execute a one-shot command inside a running service container (Admin only)')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('ref', InputArgument::REQUIRED, 'Deployed branch or tag')
            ->addArgument('service', InputArgument::REQUIRED, 'Service name in compose specification')
            ->addArgument('cmd', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Command and arguments to execute');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->stringArgument($input, 'repository');
        $ref = $this->stringArgument($input, 'ref');
        $service = $this->stringArgument($input, 'service');
        $rawCmd = $input->getArgument('cmd');
        $cmd = [];
        if (\is_array($rawCmd)) {
            foreach ($rawCmd as $token) {
                if (\is_scalar($token)) {
                    $cmd[] = (string) $token;
                }
            }
        }

        if ([] === $cmd) {
            throw new InvalidInput('command cannot be empty');
        }

        $res = ($this->execDeployCommand)($this->actor(), $repo, $ref, $service, $cmd);
        if ('' !== $res['output']) {
            $output->write($res['output'], false, OutputInterface::OUTPUT_RAW);
        }

        return 0 === $res['exitCode'] ? Command::SUCCESS : Command::FAILURE;
    }
}
