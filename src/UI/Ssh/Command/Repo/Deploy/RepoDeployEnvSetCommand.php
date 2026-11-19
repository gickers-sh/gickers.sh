<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Deploy;

use App\Deploy\Application\ManageEnvVars;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeployEnvSetCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:env:set';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly ManageEnvVars $manageEnvVars,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Set encrypted environment variables for a deployed branch or tag')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('ref', InputArgument::REQUIRED, 'Branch or tag name')
            ->addArgument('pairs', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'KEY=VALUE pairs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->stringArgument($input, 'repository');
        $ref = $this->stringArgument($input, 'ref');
        $rawPairs = $input->getArgument('pairs');

        $pairs = [];
        if (\is_array($rawPairs)) {
            foreach ($rawPairs as $item) {
                if (!\is_string($item) || !str_contains($item, '=')) {
                    throw new InvalidInput(sprintf('invalid KEY=VALUE pair "%s"', \is_scalar($item) ? (string) $item : ''));
                }
                [$k, $v] = explode('=', $item, 2);
                $pairs[$k] = $v;
            }
        }

        if ([] === $pairs) {
            throw new InvalidInput('at least one KEY=VALUE pair is required');
        }

        $this->manageEnvVars->set($this->actor(), $repo, $ref, $pairs);

        return Command::SUCCESS;
    }
}
