<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Backend\BackendService;
use App\Service\CodeIntel\CodeIntelService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'soft:hook:post-update',
    description: 'Execute Git post-update hook and reindex CodeIntel',
    aliases: ['git:hook:post-update', 'hook:post-update']
)]
final class HookPostUpdateCommand extends Command
{
    use CustomHookTrait;

    public function __construct(
        private readonly BackendService $backend,
        private readonly CodeIntelService $codeIntel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('refs', InputArgument::IS_ARRAY, 'Refs that were updated');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $refs = (array) $input->getArgument('refs');

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
        $candidatePath = $this->backend->getRepoPath($repoName);
        if (is_dir($candidatePath)) {
            $repoPath = $candidatePath;
        }

        // 1. Update last-modified in repository (via git log -1 --format=%cd / %cI)
        $this->backend->populateLastModified($repoPath);

        // 2. Trigger code intelligence indexing on HEAD if available
        $headProc = new Process(['git', 'rev-parse', 'HEAD'], $repoPath);
        $headProc->run();
        if ($headProc->isSuccessful()) {
            $headSha = trim($headProc->getOutput());
            if ('' !== $headSha) {
                try {
                    $this->codeIntel->indexCommit($repoPath, $repoName, $headSha);
                } catch (\Throwable) {
                    // Non-blocking background index
                }
            }
        }

        // 3. Run custom hook $DATA_PATH/hooks/post-update if executable
        $dataPath = getenv('SOFT_SERVE_DATA_PATH') ?: (getenv('DATA_PATH') ?: $this->backend->getDataPath());
        $customHookPath = rtrim($dataPath, '/').'/hooks/post-update';
        $exitCode = $this->runCustomHook($customHookPath, null, $refs, $output);
        if (0 !== $exitCode) {
            return $exitCode;
        }

        return Command::SUCCESS;
    }
}
