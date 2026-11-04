<?php

declare(strict_types=1);

namespace App\Webhook\Application;

use App\Identity\Domain\Model\User;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\Webhook\Domain\WebhookEvent;

/**
 * Builds webhook payload arrays matching Go `pkg/webhook/{common,push,branch_tag,collaborator,repository}.go`.
 */
final readonly class PayloadFactory
{
    public function __construct(
        private ServerSettings $serverSettings,
        private GitRepositoryReader $gitReader,
        private RepoDirectory $repoDirectory,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forPush(Repo $repo, ?User $sender, string $ref, string $before, string $after): array
    {
        $payload = $this->commonPayload($repo, $sender, WebhookEvent::Push);
        $payload['ref'] = $ref;
        $payload['before'] = $before;
        $payload['after'] = $after;

        $commits = [];
        if (!$this->isZeroSha($after)) {
            $rev = $after;
            if (!$this->isZeroSha($before)) {
                $rev = sprintf('%s..%s', $before, $after);
            }

            try {
                $gitRepo = $this->gitReader->openRepository($this->repoDirectory->pathFor($repo->name()));
                $logCommits = $this->gitReader->getCommits($gitRepo, $rev, limit: 20);
                foreach ($logCommits as $c) {
                    $dateStr = (new \DateTimeImmutable("@{$c->timestamp}", new \DateTimeZone('UTC')))
                        ->format(\DateTimeInterface::RFC3339);
                    $authorData = [
                        'name' => $c->authorName,
                        'email' => $c->authorEmail,
                        'date' => $dateStr,
                    ];
                    $commits[] = [
                        'id' => $c->hash,
                        'message' => '' !== $c->body ? $c->subject."\n\n".$c->body : $c->subject,
                        'title' => $c->subject,
                        'author' => $authorData,
                        'committer' => $authorData,
                        'timestamp' => $dateStr,
                    ];
                }
            } catch (\Throwable) {
                // If git log fails (e.g. invalid ref or detached), fall back to empty commits list
            }
        }

        $payload['commits'] = $commits;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function forBranchTag(Repo $repo, ?User $sender, string $ref, string $before, string $after): array
    {
        $isCreate = $this->isZeroSha($before);
        $isDelete = $this->isZeroSha($after);
        $event = $isCreate ? WebhookEvent::BranchTagCreate : WebhookEvent::BranchTagDelete;

        $payload = $this->commonPayload($repo, $sender, $event);
        $payload['ref'] = $ref;
        $payload['before'] = $before;
        $payload['after'] = $after;
        $payload['created'] = $isCreate;
        $payload['deleted'] = $isDelete;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function forCollaborator(Repo $repo, ?User $sender, User $collaborator, string $action, int $accessLevel): array
    {
        $payload = $this->commonPayload($repo, $sender, WebhookEvent::Collaborator);
        $payload['action'] = $action;
        $payload['access_level'] = $accessLevel;
        $payload['collaborator'] = [
            'id' => $collaborator->id(),
            'username' => $collaborator->username(),
        ];

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function forRepository(Repo $repo, ?User $sender, string $action): array
    {
        $event = 'visibility_change' === $action
            ? WebhookEvent::RepositoryVisibilityChange
            : WebhookEvent::Repository;

        $payload = $this->commonPayload($repo, $sender, $event);
        $payload['action'] = $action;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function commonPayload(Repo $repo, ?User $sender, WebhookEvent $event): array
    {
        return [
            'event' => $event->value,
            'repository' => [
                'id' => $repo->id(),
                'name' => $repo->nameString(),
                'project_name' => $repo->projectName(),
                'description' => $repo->description(),
                'default_branch' => $this->defaultBranch($repo),
                'private' => $repo->isPrivate(),
                'owner' => [
                    'id' => $repo->owner()->id(),
                    'username' => $repo->owner()->username(),
                ],
                'http_url' => $this->repoHttpUrl($repo),
                'ssh_url' => $this->repoSshUrl($repo),
                'git_url' => $this->repoGitUrl($repo),
                'created_at' => $this->formatDate($repo->createdAt()),
                'updated_at' => $this->formatDate($repo->updatedAt()),
            ],
            'sender' => [
                'id' => $sender?->id() ?? 0,
                'username' => $sender?->username() ?? '',
            ],
        ];
    }

    private function defaultBranch(Repo $repo): string
    {
        try {
            $gitRepo = $this->gitReader->openRepository($this->repoDirectory->pathFor($repo->name()));

            return $gitRepo->defaultBranch;
        } catch (\Throwable) {
            return 'main';
        }
    }

    private function repoHttpUrl(Repo $repo): string
    {
        return rtrim($this->serverSettings->httpPublicUrl, '/').'/'.$repo->nameString().'.git';
    }

    private function repoSshUrl(Repo $repo): string
    {
        return rtrim($this->serverSettings->sshPublicUrl, '/').'/'.$repo->nameString().'.git';
    }

    /**
     * Derives git:// clone URL from configured public URLs and git listen address.
     * Note: ServerSettings has no gitPublicUrl, so host is derived from httpPublicUrl (deviation documented).
     */
    private function repoGitUrl(Repo $repo): string
    {
        $host = (string) (parse_url($this->serverSettings->httpPublicUrl, \PHP_URL_HOST) ?: 'localhost');

        return 'git://'.$host.'/'.$repo->nameString().'.git';
    }

    private function formatDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::RFC3339);
    }

    private function isZeroSha(string $sha): bool
    {
        return '' === $sha || '' === trim($sha, '0');
    }
}
