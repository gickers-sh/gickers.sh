<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Repo\Application\Query\RepoQuery;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use App\UI\Tui\Render\CodeHighlighter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoBlobCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:blob';
    }

    public static function sshAliases(): array
    {
        return ['repo:cat', 'repo:show'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RepoQuery $repoQuery,
        private readonly BareRepositoryStorage $storage,
        private readonly GitRepositoryReader $gitReader,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Display contents of a file at path')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('ref_or_path', InputArgument::OPTIONAL, 'Git reference or file path if no ref')
            ->addArgument('path', InputArgument::OPTIONAL, 'File path when ref is specified')
            ->addOption('color', 'c', InputOption::VALUE_NONE, 'Colorize output')
            ->addOption('linenumber', 'l', InputOption::VALUE_NONE, 'Print line numbers')
            ->addOption('raw', 'r', InputOption::VALUE_NONE, 'Print raw file contents without binary check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $arg1 = $this->nullableStringArgument($input, 'ref_or_path');
        $arg2 = $this->nullableStringArgument($input, 'path');
        $raw = (bool) $input->getOption('raw');
        $color = (bool) $input->getOption('color');
        $lineNumber = (bool) $input->getOption('linenumber');

        if (null === $arg1) {
            throw new InvalidInput('Missing file path');
        }

        if (null !== $arg2 && '' !== trim($arg2)) {
            $ref = $arg1;
            $path = $arg2;
        } else {
            $ref = 'HEAD';
            $path = $arg1;
        }

        $repo = $this->repoQuery->get($this->actor(), $name);
        $gitRepo = $this->storage->open($repo->name());

        $content = $this->gitReader->getBlob($gitRepo, $ref, $path);

        if (!$raw && str_contains($content, "\0")) {
            $this->line($output, 'binary file: use --raw to print');

            return Command::SUCCESS;
        }

        if ($raw || (!$color && !$lineNumber) || '' === $content) {
            $this->writeRaw($output, $content);

            return Command::SUCCESS;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $trimmed = str_ends_with($normalized, "\n") ? substr($normalized, 0, -1) : $normalized;
        $lines = explode("\n", $trimmed);

        if ($color) {
            $highlighted = (new CodeHighlighter())->highlightLines($path, $content);
            if (null !== $highlighted && \count($highlighted) === \count($lines)) {
                $lines = $highlighted;
            }
        }

        if ($lineNumber) {
            $width = \strlen((string) \count($lines));
            foreach ($lines as $idx => $line) {
                $num = str_pad((string) ($idx + 1), $width, ' ', \STR_PAD_LEFT);
                $prefix = $color ? "\e[2m" . $num . "\e[0m  " : $num . '  ';
                $lines[$idx] = $prefix . $line;
            }
        }

        $this->writeRaw($output, implode("\n", $lines) . "\n");

        return Command::SUCCESS;
    }
}
