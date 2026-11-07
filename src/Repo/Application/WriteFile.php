<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Repo\Infrastructure\Git\GitRepositoryWriter;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Infrastructure\Config\ServerSettings;

/**
 * Creates or updates a single file on a branch and commits the change, then
 * records the ref update as a push so webhooks and code-intel reindexing fire
 * exactly as they do for a received push (Go `pkg/backend/repo.go` `WriteFile`,
 * which dispatches a push webhook after the commit).
 *
 * Requires read-write access. The commit is authored as the acting user with a
 * `<username>@noreply.<host>` email, matching {@see MergePullRequest}.
 */
final readonly class WriteFile
{
    public function __construct(
        private AccessGuard $accessGuard,
        private BareRepositoryStorage $storage,
        private GitRepositoryReader $reader,
        private GitRepositoryWriter $writer,
        private RecordPush $recordPush,
        private ServerSettings $settings,
    ) {
    }

    /**
     * @return string the newly created commit SHA
     */
    public function __invoke(
        ?User $actor,
        RepoName|string $name,
        string $branch,
        string $filePath,
        string $content,
        string $message,
    ): string {
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $name = $name instanceof RepoName ? $name : RepoName::fromInput($name);
        $repo = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadWriteAccess);
        \assert($repo instanceof Repo);

        $git = $this->storage->open($name);
        $oldSha = $this->branchSha($git, $branch);

        $newSha = $this->writer->writeFile(
            $git,
            $branch,
            $filePath,
            $content,
            $message,
            $actor->username(),
            $this->authorEmail($actor->username()),
        );

        ($this->recordPush)($name, [new PushChange('refs/heads/'.$branch, $oldSha, $newSha)], $actor);

        return $newSha;
    }

    private function branchSha(GitRepository $git, string $branch): string
    {
        try {
            $commits = $this->reader->getCommits($git, $branch, 1, 0);
        } catch (\Throwable) {
            return '';
        }

        return $commits[0]->hash ?? '';
    }

    private function authorEmail(string $username): string
    {
        $host = parse_url($this->settings->httpPublicUrl, \PHP_URL_HOST);
        $domain = \is_string($host) && '' !== $host ? $host : 'localhost';

        return \sprintf('%s@noreply.%s', '' !== $username ? $username : 'anonymous', $domain);
    }
}
