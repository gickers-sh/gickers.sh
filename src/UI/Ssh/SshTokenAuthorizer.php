<?php

declare(strict_types=1);

namespace App\UI\Ssh;

use App\Identity\Domain\Model\AccessToken;
use App\Identity\Domain\Model\User;
use App\Repo\Application\ForkRepo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Enforces Personal Access Token scopes and repository restrictions across the entire SSH surface
 * (CLI commands, Git transport, Git LFS authenticate, and TUI).
 *
 * Fail-closed: any command not explicitly classified requires full `admin` / `*` / `all` scope
 * and no repository restriction.
 */
final readonly class SshTokenAuthorizer
{
    public function __construct(
        private RepoStore $repoStore,
    ) {
    }

    public function assertTuiAllowed(AccessToken $token, ?string $initialRepo, ?User $actor): void
    {
        $this->assertNotEphemeralLfs($token);
        $this->assertScope($token, 'repo:read');

        if (null !== $initialRepo && '' !== trim($initialRepo)) {
            $this->assertRepoAllowed($token, $initialRepo, $actor);

            return;
        }

        if ($token->hasRepoRestrictions()) {
            throw new AccessDenied('Access token restricted to specific repositories requires -t <repo> for TUI.');
        }
    }

    public function assertGitTransportAllowed(AccessToken $token, string $gitCommand, string $rawRepo, ?User $actor): void
    {
        $this->assertNotEphemeralLfs($token);

        $requiredScope = 'git-receive-pack' === $gitCommand ? 'repo:write' : 'repo:read';
        $this->assertScope($token, $requiredScope);
        $this->assertRepoAllowed($token, $rawRepo, $actor);
    }

    public function assertLfsAuthenticateAllowed(AccessToken $token, string $rawRepo, string $operation, ?User $actor): void
    {
        $this->assertNotEphemeralLfs($token);

        $requiredScope = 'upload' === $operation ? 'lfs:upload' : 'lfs:download';
        $this->assertScope($token, $requiredScope);
        $this->assertRepoAllowed($token, $rawRepo, $actor);
    }

    public function assertCommandAllowed(
        AccessToken $token,
        string $canonicalCommand,
        InputInterface $boundInput,
        ?User $actor,
    ): void {
        $this->assertNotEphemeralLfs($token);

        // 1. Public / self-identity read-only commands (allowed for any non-LFS-only PAT)
        if (\in_array($canonicalCommand, ['about', 'info', 'username', 'wiki:list', 'wiki:show', 'wiki:search'], true)) {
            if (!$token->hasScope('repo:read') && !$token->hasScope('admin')) {
                throw new AccessDenied('Access token missing required scope for this command.');
            }

            return;
        }

        // 2. Repository listing (filtered in RepoListCommand when repo-restricted)
        if ('repo:list' === $canonicalCommand) {
            $this->assertScope($token, 'repo:read');

            return;
        }

        // 3. Pure repository read commands (single `repository` argument)
        if (\in_array($canonicalCommand, [
            'repo:info',
            'repo:tree',
            'repo:blob',
            'repo:commit',
            'repo:diff',
            'repo:grep',
            'repo:readme',
            'repo:branch:list',
            'repo:tag:list',
            'repo:collab:list',
            'repo:webhook:list',
            'repo:deploy:status',
            'repo:deploy:logs',
            'repo:deploy:domain:list',
            'repo:deploy:domain:get',
        ], true)) {
            $this->assertScope($token, 'repo:read');
            $this->assertRepoAllowed($token, $this->stringArg($boundInput, 'repository'), $actor);

            return;
        }

        // 4. Getter-or-write-setter repository commands
        if ('repo:mirror' === $canonicalCommand) {
            $repoArg = $this->stringArg($boundInput, 'repository');
            if (ForkRepo::isExternalUrl($repoArg)) {
                $this->assertScope($token, 'repo:write');
                $target = $this->stringArg($boundInput, 'mirror');
                if ('' === $target) {
                    $base = ForkRepo::extractRepoNameSource($repoArg);
                    $target = null !== $actor ? $actor->username().'/'.$base : $base;
                }
                $this->assertRepoAllowed($token, $target, $actor);

                return;
            }
        }

        $getterWriteSetters = [
            'repo:description' => 'description',
            'repo:project-name' => 'name',
            'repo:hidden' => 'hidden',
            'repo:mirror' => 'mirror',
            'repo:branch:default' => 'branch',
        ];
        if (isset($getterWriteSetters[$canonicalCommand])) {
            $valueArg = $getterWriteSetters[$canonicalCommand];
            $isMutation = $this->hasNonEmptyArgument($boundInput, $valueArg);
            $this->assertScope($token, $isMutation ? 'repo:write' : 'repo:read');
            $this->assertRepoAllowed($token, $this->stringArg($boundInput, 'repository'), $actor);

            return;
        }

        // 5. Getter-or-admin-setter repository commands (visibility / commit signatures)
        $getterAdminSetters = [
            'repo:private' => 'private',
            'repo:signatures' => 'value',
        ];
        if (isset($getterAdminSetters[$canonicalCommand])) {
            $valueArg = $getterAdminSetters[$canonicalCommand];
            $isMutation = $this->hasNonEmptyArgument($boundInput, $valueArg);
            $this->assertScope($token, $isMutation ? 'repo:admin' : 'repo:read');
            $this->assertRepoAllowed($token, $this->stringArg($boundInput, 'repository'), $actor);

            return;
        }

        // 6. Quota view vs server-admin mutation
        if ('repo:deploy:quota' === $canonicalCommand) {
            $isMutation = $this->hasAnyNonEmptyOption($boundInput, ['cpus', 'memory', 'pids', 'max-envs', 'max-services']);
            $this->assertScope($token, $isMutation ? 'admin' : 'repo:read');
            $this->assertRepoAllowed($token, $this->stringArg($boundInput, 'repository'), $actor);

            return;
        }

        // 7. Deploy allow view vs server-admin mutation
        if ('repo:deploy:allow' === $canonicalCommand) {
            $isMutation = $this->hasNonEmptyArgument($boundInput, 'allowed');
            $this->assertScope($token, $isMutation ? 'admin' : 'repo:read');
            $this->assertRepoAllowed($token, $this->stringArg($boundInput, 'repository'), $actor);

            return;
        }

        // 8. Deploy exec (server-admin only)
        if ('repo:deploy:exec' === $canonicalCommand) {
            $this->assertScope($token, 'admin');
            $this->assertRepoAllowed($token, $this->stringArg($boundInput, 'repository'), $actor);

            return;
        }

        // 9. Deploy redeploy (--all is server-wide admin; single repo is repo:write)
        if ('repo:deploy:redeploy' === $canonicalCommand) {
            if ($boundInput->hasOption('all') && (bool) $boundInput->getOption('all')) {
                $this->assertUnrestrictedAdmin($token);

                return;
            }
            $this->assertScope($token, 'repo:write');
            $repoArg = $this->stringArg($boundInput, 'repository');
            if ('' !== $repoArg) {
                $this->assertRepoAllowed($token, $repoArg, $actor);
            }

            return;
        }

        // 10. Fork (source requires repo:read; target requires repo:write)
        if ('repo:fork' === $canonicalCommand) {
            $source = $this->stringArg($boundInput, 'source');
            $this->assertScope($token, 'repo:read');
            if (!ForkRepo::isExternalUrl($source)) {
                $this->assertRepoAllowed($token, $source, $actor);
            }

            $this->assertScope($token, 'repo:write');
            $target = $this->stringArg($boundInput, 'new_name');
            if ('' === $target) {
                $base = ForkRepo::extractRepoNameSource($source);
                $target = null !== $actor ? $actor->username().'/'.$base : $base;
            }
            $this->assertRepoAllowed($token, $target, $actor);

            return;
        }

        // 11. Rename (both old and new repository names require repo:admin)
        if ('repo:rename' === $canonicalCommand) {
            $this->assertScope($token, 'repo:admin');
            $this->assertRepoAllowed($token, $this->stringArg($boundInput, 'repository'), $actor);
            $this->assertRepoAllowed($token, $this->stringArg($boundInput, 'new_name'), $actor);

            return;
        }

        // 12. Pure repository write commands
        if (\in_array($canonicalCommand, [
            'repo:create',
            'repo:import',
            'repo:branch:delete',
            'repo:tag:delete',
            'repo:deploy:trigger',
            'repo:deploy:restart',
            'repo:deploy:stop',
            'repo:deploy:enable',
            'repo:deploy:disable',
        ], true)) {
            $this->assertScope($token, 'repo:write');
            $this->assertRepoAllowed($token, $this->stringArg($boundInput, 'repository'), $actor);

            return;
        }

        // 13. Pure repository admin commands
        if (\in_array($canonicalCommand, [
            'repo:delete',
            'repo:collab:add',
            'repo:collab:remove',
            'repo:branch:protect',
            'repo:branch:unprotect',
            'repo:webhook:create',
            'repo:webhook:delete',
            'repo:deploy:env:list',
            'repo:deploy:env:get',
            'repo:deploy:env:set',
            'repo:deploy:env:unset',
            'repo:deploy:domain:add',
            'repo:deploy:domain:remove',
        ], true)) {
            $this->assertScope($token, 'repo:admin');
            $this->assertRepoAllowed($token, $this->stringArg($boundInput, 'repository'), $actor);

            return;
        }

        // 14. Fail-closed default: identity, account management, server settings, users, wiki mutations, onboarding
        $this->assertUnrestrictedAdmin($token);
    }

    private function assertNotEphemeralLfs(AccessToken $token): void
    {
        if ($token->isEphemeralLfsOnly()) {
            throw new AccessDenied('Git LFS ephemeral token cannot be used over SSH.');
        }
    }

    private function assertScope(AccessToken $token, string $requiredScope): void
    {
        if (!$token->hasScope($requiredScope)) {
            throw new AccessDenied(sprintf('Access token missing required scope "%s".', $requiredScope));
        }
    }

    private function assertUnrestrictedAdmin(AccessToken $token): void
    {
        if (!$token->hasScope('admin') || $token->hasRepoRestrictions()) {
            throw new AccessDenied('Access token requires unrestricted full scope ("*" or "admin") for this command.');
        }
    }

    private function assertRepoAllowed(AccessToken $token, string $rawRepo, ?User $actor): void
    {
        if (!$token->hasRepoRestrictions()) {
            return;
        }

        try {
            $repoName = RepoName::fromInput($rawRepo);
        } catch (InvalidInput) {
            throw new AccessDenied(sprintf('Access token is not authorized for repository "%s".', $rawRepo));
        }

        $effective = $repoName->value;
        if (null !== $actor && !str_contains($effective, '/') && null === $this->repoStore->byName($repoName)) {
            $scopedName = RepoName::fromInput($actor->username().'/'.$effective);
            if (null !== $this->repoStore->byName($scopedName)) {
                $effective = $scopedName->value;
            }
        }

        if (!$token->allowsRepo($effective)) {
            throw new AccessDenied(sprintf('Access token is not authorized for repository "%s".', $effective));
        }
    }

    private function stringArg(InputInterface $input, string $name): string
    {
        if (!$input->hasArgument($name)) {
            return '';
        }

        $val = $input->getArgument($name);

        return \is_string($val) ? trim($val) : '';
    }

    private function hasNonEmptyArgument(InputInterface $input, string $name): bool
    {
        if (!$input->hasArgument($name)) {
            return false;
        }

        $val = $input->getArgument($name);
        if (\is_array($val)) {
            return [] !== $val;
        }

        return \is_string($val) && '' !== trim($val);
    }

    /**
     * @param list<string> $names
     */
    private function hasAnyNonEmptyOption(InputInterface $input, array $names): bool
    {
        foreach ($names as $name) {
            if (!$input->hasOption($name)) {
                continue;
            }
            $val = $input->getOption($name);
            if (\is_string($val) && '' !== trim($val)) {
                return true;
            }
            if (\is_bool($val) && $val) {
                return true;
            }
        }

        return false;
    }
}
