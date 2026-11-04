<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Backend\BackendService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:hook:update',
    description: 'Execute Git update hook',
    aliases: ['git:hook:update', 'hook:update']
)]
final class HookUpdateCommand extends Command
{
    use CustomHookTrait;

    public function __construct(
        private readonly BackendService $backend,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('ref-name', InputArgument::REQUIRED, 'The name of the ref being updated')
            ->addArgument('old-rev', InputArgument::REQUIRED, 'The old object name stored in the ref')
            ->addArgument('new-rev', InputArgument::REQUIRED, 'The new object name to be stored in the ref');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $refName = (string) $input->getArgument('ref-name');
        $oldSha = (string) $input->getArgument('old-rev');
        $newSha = (string) $input->getArgument('new-rev');

        $rawRepoName = getenv('SOFT_SERVE_REPO_NAME');
        $repoPath = getenv('SOFT_SERVE_REPO_PATH') ?: (getenv('CODES_SH_REPO_PATH') ?: (getcwd() ?: '.'));
        if (false === $rawRepoName || '' === $rawRepoName) {
            $repoName = basename($repoPath);
            if (str_ends_with($repoName, '.git')) {
                $repoName = substr($repoName, 0, -4);
            }
        } else {
            $repoName = (string) $rawRepoName;
        }

        // Trigger backend update (branch_tag and push webhooks with user auth)
        $this->backend->updateHook($repoName, $refName, $oldSha, $newSha);

        // Run custom hook $DATA_PATH/hooks/update if executable
        $dataPath = getenv('SOFT_SERVE_DATA_PATH') ?: (getenv('DATA_PATH') ?: $this->backend->getDataPath());
        $customHookPath = rtrim($dataPath, '/').'/hooks/update';
        $exitCode = $this->runCustomHook($customHookPath, null, [$refName, $oldSha, $newSha], $output);
        if (0 !== $exitCode) {
            return $exitCode;
        }

        return Command::SUCCESS;
    }
}
