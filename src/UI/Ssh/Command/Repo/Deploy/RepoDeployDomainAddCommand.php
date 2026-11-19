<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Deploy;

use App\Deploy\Application\ManageDomains;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeployDomainAddCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:domain:add';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly ManageDomains $manageDomains,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Attach a custom domain to an explicit service and internal port on a deployed branch or tag')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('ref', InputArgument::REQUIRED, 'Branch or tag name')
            ->addArgument('domain', InputArgument::REQUIRED, 'Fully qualified domain name (e.g. app.example.com)')
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Target service name in compose.yaml (required)')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Target internal container port (required)')
            ->addOption('skip-dns-check', null, InputOption::VALUE_NONE, 'Skip public DNS resolution check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->stringArgument($input, 'repository');
        $ref = $this->stringArgument($input, 'ref');
        $domainInput = $this->stringArgument($input, 'domain');

        $rawService = $input->getOption('service');
        if (!\is_string($rawService) || '' === trim($rawService)) {
            throw new InvalidInput('--service=<service> is required (no service is exposed automatically)');
        }

        $rawPort = $input->getOption('port');
        if (!\is_string($rawPort) || '' === trim($rawPort) || !ctype_digit(trim($rawPort))) {
            throw new InvalidInput('--port=<port> is required (internal container port, e.g. --port=80)');
        }

        $skipDns = (bool) $input->getOption('skip-dns-check');

        $domain = $this->manageDomains->add(
            actor: $this->actor(),
            repoName: $repo,
            ref: $ref,
            domainInput: $domainInput,
            serviceName: trim($rawService),
            targetPort: (int) trim($rawPort),
            skipDnsCheck: $skipDns,
        );

        $smoke = null !== $domain->lastSmokeStatus() ? sprintf(' (smoke HTTP %d)', $domain->lastSmokeStatus()) : '';
        $this->line($output, sprintf(
            'Domain %s bound to %s@%s -> %s:%d%s',
            $domain->domain(),
            $domain->environment()->repo()->nameString(),
            $domain->environment()->refName(),
            $domain->serviceName(),
            $domain->targetPort(),
            $smoke,
        ));

        return Command::SUCCESS;
    }
}
