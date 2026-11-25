<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command;

use App\Shared\Domain\Exception\AccessDenied;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class InfoCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'info';
    }

    public function __construct(
        SshSession $sshSession,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this->setDescription('Show your user info');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = $this->actor();
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $this->line($output, $this->formatKeyValue($output, 'Username', $actor->username(), \App\UI\Tui\Render\Theme::CYAN, true));
        $this->line($output, $this->formatKeyValue($output, 'Admin', $actor->isAdmin()));
        $this->line($output, $this->formatHeader($output, 'Public keys:'));

        foreach ($actor->publicKeys() as $key) {
            $this->line($output, '  ' . $this->formatPublicKey($output, $key->authorizedKey()));
        }

        return Command::SUCCESS;
    }
}
