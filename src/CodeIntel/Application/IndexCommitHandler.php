<?php

declare(strict_types=1);

namespace App\CodeIntel\Application;

use App\CodeIntel\Application\Message\IndexCommit;
use App\CodeIntel\Domain\IndexStore;
use App\CodeIntel\Infrastructure\GitArchiveExtractor;
use App\CodeIntel\Infrastructure\Indexer\Indexer;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Builds and stores the SCIP index for one commit: exports the commit tree, runs the first
 * supporting indexer that yields a non-null index (scip-go > scip-php > native PHP), and persists
 * the result.
 *
 * Indexing must never fail a push, so all throwables are logged and swallowed. The handler is also
 * directly invokable (`$handler(new IndexCommit(...))`) for the synchronous admin reindex command.
 */
#[AsMessageHandler]
final readonly class IndexCommitHandler
{
    private LoggerInterface $logger;

    /**
     * @param iterable<Indexer> $indexers ordered by priority (highest first)
     */
    public function __construct(
        private RepoDirectory $repoDirectory,
        private GitArchiveExtractor $extractor,
        private IndexStore $indexStore,
        #[AutowireIterator('app.scip_indexer')]
        private iterable $indexers,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function __invoke(IndexCommit $message): void
    {
        $projectDir = null;
        try {
            $repoPath = $this->repoDirectory->pathFor(RepoName::fromInput($message->repo));
            $projectDir = $this->extractor->extract($repoPath, $message->sha);

            foreach ($this->indexers as $indexer) {
                if (!$indexer->supports($projectDir)) {
                    continue;
                }

                $binary = $indexer->index($projectDir);
                if (null !== $binary) {
                    $this->indexStore->store($message->repo, $message->sha, $binary);
                    $this->logger->info('Indexed commit with SCIP', ['repo' => $message->repo, 'sha' => $message->sha]);

                    return;
                }
            }

            $this->logger->debug('No indexer produced a SCIP index', ['repo' => $message->repo, 'sha' => $message->sha]);
        } catch (\Throwable $e) {
            $this->logger->error('CodeIntel indexing failed: '.$e->getMessage(), ['repo' => $message->repo, 'sha' => $message->sha]);
        } finally {
            if (null !== $projectDir) {
                $this->extractor->cleanup($projectDir);
            }
        }
    }
}
