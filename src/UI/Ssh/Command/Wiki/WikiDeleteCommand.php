<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Wiki;

use App\Shared\Domain\Exception\AccessDenied;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use App\Wiki\Application\DeleteWikiPage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class WikiDeleteCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'wiki:delete';
    }

    public static function sshAliases(): array
    {
        return ['wiki:rm', 'wiki:del', 'wiki:remove'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly DeleteWikiPage $deleteWikiPage,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Delete a wiki page (server admin only)')
            ->addArgument('slug', InputArgument::REQUIRED, 'Wiki page slug to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = $this->actor();
        if (null === $actor || !$actor->isAdmin()) {
            throw new AccessDenied('only server admins can delete wiki pages');
        }

        $slug = $this->stringArgument($input, 'slug');
        ($this->deleteWikiPage)($this->actor(), $slug);

        return Command::SUCCESS;
    }
}
