<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Wiki;

use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use App\Wiki\Application\SaveWikiPage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class WikiSetCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'wiki:set';
    }

    public static function sshAliases(): array
    {
        return ['wiki:edit', 'wiki:create', 'wiki:save', 'wiki:write'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly SaveWikiPage $saveWikiPage,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Create or update a wiki page (server admin only)')
            ->addArgument('slug', InputArgument::REQUIRED, 'Wiki page slug (e.g. getting-started)')
            ->addArgument('content', InputArgument::IS_ARRAY, 'Markdown content (or pipe via stdin)')
            ->addOption('title', 't', InputOption::VALUE_REQUIRED, 'Page title (defaults to # Heading or slug)')
            ->addOption('category', 'c', InputOption::VALUE_REQUIRED, 'Page category (defaults to General)')
            ->addOption('position', 'p', InputOption::VALUE_REQUIRED, 'Display sort order position (integer)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $this->stringArgument($input, 'slug');
        $title = $this->nullableStringOption($input, 'title');
        $category = $this->nullableStringOption($input, 'category');
        $posOpt = $this->nullableStringOption($input, 'position');
        $position = null !== $posOpt ? (int) $posOpt : null;

        /** @var list<string> $contentArgs */
        $contentArgs = (array) $input->getArgument('content');
        $content = implode(' ', $contentArgs);

        if ('' === $content || '-' === $content) {
            $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
            if (null !== $stream && is_resource($stream)) {
                $piped = stream_get_contents($stream);
                if (is_string($piped)) {
                    $content = $piped;
                }
            }
        }

        if ('' === trim($content)) {
            throw new InvalidInput('wiki page content cannot be empty');
        }

        ($this->saveWikiPage)(
            actor: $this->actor(),
            slug: $slug,
            title: $title,
            content: $content,
            category: $category,
            position: $position,
        );

        return Command::SUCCESS;
    }
}
