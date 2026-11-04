<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Backend\BackendService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:hook:pre-receive',
    description: 'Execute Git pre-receive hook',
    aliases: ['git:hook:pre-receive', 'hook:pre-receive']
)]
final class HookPreReceiveCommand extends Command
{
    use CustomHookTrait;

    public function __construct(
        private readonly BackendService $backend,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputData = $this->getStdinData();

        $opts = [];
        if (trim($inputData) !== '') {
            $lines = explode("\n", trim($inputData));
            foreach ($lines as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) < 3) {
                    continue;
                }
                [$oldRev, $newRev, $refName] = $parts;
                $opts[] = [
                    'old_sha' => $oldRev,
                    'new_sha' => $newRev,
                    'ref_name' => $refName,
                    'old' => $oldRev,
                    'new' => $newRev,
                    'ref' => $refName,
                ];
            }
        }

        $rawRepoName = getenv('SOFT_SERVE_REPO_NAME');
        $repoPath = getenv('SOFT_SERVE_REPO_PATH') ?: (getenv('CODES_SH_REPO_PATH') ?: (getcwd() ?: '.'));
        if ($rawRepoName === false || $rawRepoName === '') {
            $repoName = basename($repoPath);
            if (str_ends_with($repoName, '.git')) {
                $repoName = substr($repoName, 0, -4);
            }
        } else {
            $repoName = (string) $rawRepoName;
        }

        // Pass to backend PreReceive
        $this->backend->preReceive($repoName, $opts);

        // Run custom hook $DATA_PATH/hooks/pre-receive if executable
        $dataPath = getenv('SOFT_SERVE_DATA_PATH') ?: (getenv('DATA_PATH') ?: $this->backend->getDataPath());
        $customHookPath = rtrim($dataPath, '/') . '/hooks/pre-receive';
        $exitCode = $this->runCustomHook($customHookPath, $inputData, [], $output);
        if ($exitCode !== 0) {
            return $exitCode;
        }

        return Command::SUCCESS;
    }
}
