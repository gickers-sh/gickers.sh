<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Backend\BackendService;
use App\Service\CodeIntel\CodeIntelService;
use App\Service\Webhook\WebhookDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:hook:post-receive',
    description: 'Execute Git post-receive hook and trigger webhooks',
    aliases: ['git:hook:post-receive', 'hook:post-receive']
)]
final class HookPostReceiveCommand extends Command
{
    use CustomHookTrait;

    public function __construct(
        private readonly BackendService $backend,
        private readonly WebhookDispatcher $webhookDispatcher,
        private readonly ?CodeIntelService $codeIntel = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputData = $this->getStdinData();

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

        // Parse stdin lines: <old-rev> <new-rev> <ref-name>
        $changes = [];
        if ('' !== trim($inputData)) {
            $lines = explode("\n", trim($inputData));
            foreach ($lines as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) < 3) {
                    continue;
                }
                [$oldRev, $newRev, $refName] = $parts;
                $changes[] = [
                    'old' => $oldRev,
                    'new' => $newRev,
                    'ref' => $refName,
                ];

                // Trigger code intelligence indexing on each non-zero new-sha
                if (null !== $this->codeIntel && !WebhookDispatcher::isZeroHash($newRev)) {
                    try {
                        $this->codeIntel->indexCommit($repoPath, $repoName, $newRev);
                    } catch (\Throwable) {
                        // Non-blocking background index
                    }
                }
            }
        }

        // Trigger push webhooks
        if (count($changes) > 0) {
            $user = null;
            $pubkey = getenv('SOFT_SERVE_PUBLIC_KEY');
            $username = getenv('SOFT_SERVE_USERNAME');
            if (false !== $pubkey && '' !== $pubkey) {
                $user = $this->backend->getUserByPublicKey($pubkey);
            } elseif (false !== $username && '' !== $username) {
                $user = $this->backend->getUserByUsername($username);
            }

            $this->webhookDispatcher->dispatchPushEvent($repoName, $changes, $user);
        }

        // Run custom hook $DATA_PATH/hooks/post-receive if executable
        $dataPath = getenv('SOFT_SERVE_DATA_PATH') ?: (getenv('DATA_PATH') ?: $this->backend->getDataPath());
        $customHookPath = rtrim($dataPath, '/').'/hooks/post-receive';
        $exitCode = $this->runCustomHook($customHookPath, $inputData, [], $output);
        if (0 !== $exitCode) {
            return $exitCode;
        }

        return Command::SUCCESS;
    }
}
