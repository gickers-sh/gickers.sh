<?php

declare(strict_types=1);

namespace App\UI\Console\Command\Hook;

use App\Identity\Domain\UserStore;
use App\Repo\Application\PushChange;
use App\Repo\Application\RecordPush;
use App\Repo\Infrastructure\Git\CustomHookRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:hook:post-receive',
    description: 'Execute Git post-receive hook and record push',
    aliases: ['git:hook:post-receive', 'hook:post-receive'],
)]
final class PostReceiveCommand extends Command
{
    private ?string $inputStream = null;

    public function __construct(
        private readonly RecordPush $recordPush,
        private readonly CustomHookRunner $customHookRunner,
        private readonly UserStore $userStore,
    ) {
        parent::__construct();
    }

    public function setInputStream(?string $input): void
    {
        $this->inputStream = $input;
    }

    protected function configure(): void
    {
        $this
            ->addOption('repo', null, InputOption::VALUE_OPTIONAL, 'Repository name')
            ->addOption('actor', null, InputOption::VALUE_OPTIONAL, 'Actor username')
            ->addOption('stdin', null, InputOption::VALUE_OPTIONAL, 'Explicit stdin payload');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stdin = $this->resolveStdin($input);
        $repoName = $this->resolveRepoName($input);

        $changes = [];
        if ('' !== trim($stdin)) {
            $lines = explode("\n", trim($stdin));
            foreach ($lines as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (false === $parts || count($parts) < 3) {
                    continue;
                }
                [$oldSha, $newSha, $ref] = $parts;
                $changes[] = new PushChange($ref, $oldSha, $newSha);
            }
        }

        if ([] !== $changes && '' !== $repoName) {
            $actor = null;
            $actorOpt = $input->getOption('actor');
            if (is_string($actorOpt) && '' !== trim($actorOpt)) {
                $actor = $this->userStore->byUsername(trim($actorOpt));
            }

            ($this->recordPush)($repoName, $changes, $actor);
        }

        $exitCode = $this->customHookRunner->run(
            hookName: 'post-receive',
            stdin: $stdin,
            args: [],
            env: [],
            onOutput: static function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            },
        );

        return 0 !== $exitCode ? $exitCode : Command::SUCCESS;
    }

    private function resolveRepoName(InputInterface $input): string
    {
        $repoOpt = $input->getOption('repo');
        if (is_string($repoOpt) && '' !== trim($repoOpt)) {
            return trim($repoOpt);
        }

        $cwd = getcwd();
        if (false !== $cwd) {
            $name = basename($cwd);
            if (str_ends_with($name, '.git')) {
                return substr($name, 0, -4);
            }
        }

        return '';
    }

    private function resolveStdin(InputInterface $input): string
    {
        if (null !== $this->inputStream) {
            return $this->inputStream;
        }

        $stdinOpt = $input->getOption('stdin');
        if (is_string($stdinOpt) && '' !== $stdinOpt) {
            return $stdinOpt;
        }

        $raw = @file_get_contents('php://stdin');

        return false !== $raw ? $raw : '';
    }
}
