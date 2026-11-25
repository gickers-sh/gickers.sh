<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Deploy;

use App\Deploy\Application\ManageDomains;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeployDomainGetCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:domain:get';
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
            ->setDescription('Get details of a domain attached to a deployed branch or tag')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('ref', InputArgument::REQUIRED, 'Branch or tag name')
            ->addArgument('domain', InputArgument::REQUIRED, 'Domain name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->stringArgument($input, 'repository');
        $ref = $this->stringArgument($input, 'ref');
        $domainInput = $this->stringArgument($input, 'domain');

        $domain = $this->manageDomains->get($this->actor(), $repo, $ref, $domainInput);
        $dns = null !== $domain->dnsVerifiedAt() ? 'verified' : 'unverified';
        $smoke = null !== $domain->lastSmokeStatus() ? (string) $domain->lastSmokeStatus() : 'none';

        if ($this->isDecorated($output)) {
            $this->line($output, sprintf(
                '%s%s %s%s %s%s %s%s %s%s %s%s %s%s',
                \App\UI\Tui\Render\Theme::style('domain=', \App\UI\Tui\Render\Theme::BLUE),
                \App\UI\Tui\Render\Theme::style($domain->domain(), \App\UI\Tui\Render\Theme::BLUE, bold: true, underline: true),
                \App\UI\Tui\Render\Theme::style('ref=', \App\UI\Tui\Render\Theme::BLUE),
                \App\UI\Tui\Render\Theme::style($domain->environment()->refName(), \App\UI\Tui\Render\Theme::CYAN),
                \App\UI\Tui\Render\Theme::style('service=', \App\UI\Tui\Render\Theme::BLUE),
                \App\UI\Tui\Render\Theme::style($domain->serviceName(), \App\UI\Tui\Render\Theme::TEAL),
                \App\UI\Tui\Render\Theme::style('port=', \App\UI\Tui\Render\Theme::BLUE),
                \App\UI\Tui\Render\Theme::style((string) $domain->targetPort(), \App\UI\Tui\Render\Theme::ORANGE),
                \App\UI\Tui\Render\Theme::style('tls=', \App\UI\Tui\Render\Theme::BLUE),
                \App\UI\Tui\Render\Theme::style($domain->isTlsEnabled() ? 'true' : 'false', $domain->isTlsEnabled() ? \App\UI\Tui\Render\Theme::GREEN : \App\UI\Tui\Render\Theme::MUTED, bold: true),
                \App\UI\Tui\Render\Theme::style('dns=', \App\UI\Tui\Render\Theme::BLUE),
                \App\UI\Tui\Render\Theme::style($dns, 'verified' === $dns ? \App\UI\Tui\Render\Theme::GREEN : \App\UI\Tui\Render\Theme::SELECTOR, bold: true),
                \App\UI\Tui\Render\Theme::style('smoke_http=', \App\UI\Tui\Render\Theme::BLUE),
                \App\UI\Tui\Render\Theme::style($smoke, 'none' === $smoke ? \App\UI\Tui\Render\Theme::MUTED : \App\UI\Tui\Render\Theme::ORANGE),
            ));
        } else {
            $this->line($output, sprintf(
                'domain=%s ref=%s service=%s port=%d tls=%s dns=%s smoke_http=%s',
                $domain->domain(),
                $domain->environment()->refName(),
                $domain->serviceName(),
                $domain->targetPort(),
                $domain->isTlsEnabled() ? 'true' : 'false',
                $dns,
                $smoke,
            ));
        }

        return Command::SUCCESS;
    }
}
