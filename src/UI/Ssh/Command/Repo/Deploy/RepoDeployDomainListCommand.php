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

final class RepoDeployDomainListCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:domain:list';
    }

    /**
     * @return list<string>
     */
    public static function sshAliases(): array
    {
        return ['repo:deploy:domain:ls'];
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
            ->setDescription('List domains attached to a repository or specific branch/tag')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('ref', InputArgument::OPTIONAL, 'Optional branch or tag name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->stringArgument($input, 'repository');
        $ref = $this->nullableStringArgument($input, 'ref');

        $domains = $this->manageDomains->list($this->actor(), $repo, $ref);
        foreach ($domains as $domain) {
            if ($this->isDecorated($output)) {
                $this->line($output, sprintf(
                    '%s (%s) %s %s%s%s',
                    \App\UI\Tui\Render\Theme::style($domain->domain(), \App\UI\Tui\Render\Theme::BLUE, bold: true, underline: true),
                    \App\UI\Tui\Render\Theme::style($domain->environment()->refName(), \App\UI\Tui\Render\Theme::CYAN),
                    \App\UI\Tui\Render\Theme::style('->', \App\UI\Tui\Render\Theme::MUTED),
                    \App\UI\Tui\Render\Theme::style($domain->serviceName(), \App\UI\Tui\Render\Theme::TEAL),
                    \App\UI\Tui\Render\Theme::style(':', \App\UI\Tui\Render\Theme::MUTED),
                    \App\UI\Tui\Render\Theme::style((string) $domain->targetPort(), \App\UI\Tui\Render\Theme::ORANGE),
                ));
            } else {
                $this->line($output, sprintf(
                    '%s (%s) -> %s:%d',
                    $domain->domain(),
                    $domain->environment()->refName(),
                    $domain->serviceName(),
                    $domain->targetPort(),
                ));
            }
        }

        return Command::SUCCESS;
    }
}
