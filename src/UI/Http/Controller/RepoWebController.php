<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Access\Application\AccessResolver;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Http\ReadmeRenderer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lightweight repository page: `GET /{repo}` renders the README, clone command and
 * repository metadata as escaped HTML. Private repositories remain indistinguishable
 * from missing repositories to callers without read access.
 *
 * This controller also dispatches Go's `?go-get=1` discovery requests.
 */
final readonly class RepoWebController
{
    public function __construct(
        private RepoStore $repoStore,
        private AccessResolver $accessResolver,
        private GitRepositoryReader $reader,
        private BareRepositoryStorage $storage,
        private ServerSettings $settings,
        private Security $security,
        private GoGetController $goGet,
        private \Twig\Environment $twig,
        private ReadmeRenderer $readmeRenderer,
    ) {}

    // Lowest-priority catch-all: every specific git/LFS/raw route (all of which carry
    // a fixed suffix) wins over it, so only otherwise-unmatched GETs reach the page or the 404.
    #[Route('/{repo}', requirements: ['repo' => '.+'], methods: ['GET'], priority: -110)]
    public function __invoke(Request $request, string $repo): Response
    {
        // Go tooling's `?go-get=1` discovery request targets the same URL and has its own handler.
        // It is dispatched here: a route `condition` on the query string would need
        // symfony/expression-language, which is not installed (it would 500 every request).
        if ('1' === $request->query->get('go-get')) {
            return ($this->goGet)($request, $repo);
        }

        try {
            // Sanitizing strips surrounding slashes and a trailing ".git" before validating.
            $name = RepoName::fromInput($repo);
        } catch (InvalidInput) {
            return $this->notFound();
        }

        $entity = $this->repoStore->byName($name);
        $level = $this->accessResolver->levelFor($this->domainUser(), $name, $entity);
        if (null === $entity || !$level->canRead()) {
            return $this->notFound();
        }
        if (!$this->storage->exists($name)) {
            return $this->notFound();
        }

        $git = $this->storage->open($name);
        $hasCommits = null !== $this->reader->objectType($git, 'HEAD');
        $readme = $hasCommits ? $this->reader->readme($git) : null;
        $readmeHtml = null === $readme ? null : $this->readmeRenderer->render($readme['content']);

        $cloneCommand = $this->cloneCommand($name);
        $sshPrefix = $this->sshPrefix();
        $sshUrlBase = $this->sshUrlBase();
        $sshLoginUrl = '' !== $sshUrlBase ? $sshUrlBase . '/web%20login' : 'ssh://' . ($this->settings->name ?: 'codes.sh') . '/web%20login';
        $cliCommands = $this->cliCommands($name, $level);

        $response = new Response(
            $this->twig->render('repo/show.html.twig', [
                'repository' => $name->value,
                'description' => $entity->description(),
                'cloneCommand' => $cloneCommand,
                'sshPrefix' => $sshPrefix,
                'sshUrlBase' => $sshUrlBase,
                'sshLoginUrl' => $sshLoginUrl,
                'cliCommands' => $cliCommands,
                'canWrite' => $level->canWrite(),
                'isAdmin' => $level->isAdmin(),
                'readmePath' => $readme['path'] ?? null,
                'readmeHtml' => $readmeHtml,
                'empty' => !$hasCommits,
                'serverName' => $this->settings->name,
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Security-Policy' => "default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self' data:; font-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
                'X-Content-Type-Options' => 'nosniff',
            ],
        );

        return $response;
    }

    /**
     * The `git clone` command advertised for the repository, or an empty string when no
     * public SSH URL is configured. Same format as the TUI's `RepoState::cloneCommand()`.
     */
    private function cloneCommand(RepoName $name): string
    {
        if ('' === $this->settings->sshPublicUrl) {
            return '';
        }

        return 'git clone ' . rtrim($this->settings->sshPublicUrl, '/') . '/' . $name->value . '.git';
    }

    /**
     * Resolves the base SSH CLI command prefix (e.g. `ssh codes.sh` or `ssh -p 2223 localhost`).
     */
    private function sshPrefix(): string
    {
        $raw = trim($this->settings->sshPublicUrl);
        if ('' === $raw) {
            return 'ssh codes.sh';
        }

        $url = str_contains($raw, '://') ? $raw : 'ssh://' . $raw;
        $parsed = parse_url($url);
        if (false === $parsed || !isset($parsed['host'])) {
            return 'ssh ' . $raw;
        }

        $host = $parsed['host'];
        $port = isset($parsed['port']) && 22 !== (int) $parsed['port'] ? (int) $parsed['port'] : null;
        $user = $parsed['user'] ?? null;

        $target = null !== $user && '' !== $user ? "{$user}@{$host}" : $host;

        return null !== $port ? "ssh -p {$port} {$target}" : "ssh {$target}";
    }

    /**
     * Base of an `ssh://` URL for this server (e.g. `ssh://codes.sh:2223`), or an
     * empty string when no public SSH URL is configured. The client appends the
     * remote command as the URL path.
     */
    private function sshUrlBase(): string
    {
        $raw = trim($this->settings->sshPublicUrl);
        if ('' === $raw) {
            return '';
        }

        $url = str_contains($raw, '://') ? $raw : 'ssh://' . $raw;
        $parsed = parse_url($url);
        if (false === $parsed || !isset($parsed['host'])) {
            return '';
        }

        $authority = $parsed['host'];
        if (isset($parsed['user']) && '' !== $parsed['user']) {
            $authority = $parsed['user'] . '@' . $authority;
        }
        if (isset($parsed['port'])) {
            $authority .= ':' . (int) $parsed['port'];
        }

        return 'ssh://' . $authority;
    }

    /**
     * The palette of SSH CLI commands for this repository, filtered to what the
     * viewer may run. Each command carries its fixed `base` (prefix + repo) plus a
     * structured list of editable positional arguments and options, so the UI can
     * rebuild the full command and an `ssh://` link as the user types.
     *
     * @return list<array{
     *     category: string, tier: string, title: string, description: string,
     *     base: string, remote: string, command: string,
     *     fields: list<array{kind: string, key: string, label: string, placeholder: string, required: bool, default: string, flag: string}>
     * }>
     */
    private function cliCommands(RepoName $name, AccessLevel $level): array
    {
        $r = $name->value;
        $prefix = $this->sshPrefix();

        // [category, tier, title, description, base (remote, no prefix), fields].
        $all = [
            ['explore', 'read', 'TUI Interactive', 'Open the repository in the interactive terminal TUI', "-t {$r}", []],
            ['explore', 'read', 'Repository Info', 'Display metadata, default branch, disk usage and statistics', "repo info {$r}", []],
            ['explore', 'read', 'View README', 'Print the repository README in the terminal', "repo readme {$r}", [
                $this->arg('reference', 'Reference', 'HEAD'),
            ]],
            ['files', 'read', 'File Tree', 'Browse repository files and directory tree', "repo tree {$r}", [
                $this->arg('reference', 'Reference', 'HEAD'),
                $this->arg('path', 'Path', 'src/'),
            ]],
            ['files', 'read', 'Inspect File (Blob)', 'Print file contents with optional highlighting and line numbers', "repo blob {$r}", [
                $this->arg('reference', 'Reference', 'HEAD'),
                $this->arg('path', 'Path', 'README.md', true),
                $this->boolOpt('color', 'Syntax highlight', '-c', true),
                $this->boolOpt('lines', 'Line numbers', '-l', true),
            ]],
            ['files', 'read', 'Code Search (Grep)', 'Search a regex pattern across repository files', "repo grep {$r}", [
                $this->arg('pattern', 'Pattern', 'TODO|FIXME', true),
                $this->arg('reference', 'Reference', 'HEAD'),
            ]],
            ['git', 'read', 'Commit Log', 'View commit history and authors', "repo commit {$r}", [
                $this->arg('ref', 'Commit / ref', 'HEAD'),
            ]],
            ['git', 'read', 'Inspect Diff', 'Inspect changes between two references', "repo diff {$r}", [
                $this->arg('from', 'From', 'HEAD~1', true, 'HEAD~1'),
                $this->arg('to', 'To', 'HEAD', false, 'HEAD'),
            ]],
            ['git', 'read', 'List Branches', 'List all repository branches', "repo branch list {$r}", []],
            ['git', 'read', 'List Tags', 'List all repository tags', "repo tag list {$r}", []],
            ['git', 'read', 'Commit Signatures', 'Show the signed-commit enforcement status', "repo signatures {$r}", []],

            ['write', 'write', 'Fork Repository', 'Create a personal fork of this repository', "repo fork {$r}", [
                $this->arg('new-name', 'New name', 'my-fork'),
            ]],
            ['write', 'write', 'Import Mirror', 'Import or refresh this repository from a remote', "repo import {$r}", [
                $this->arg('remote', 'Remote URL', 'https://github.com/owner/repo.git', true),
            ]],

            ['admin', 'admin', 'Set Description', 'Set the repository description', "repo description {$r}", [
                $this->arg('text', 'Description', 'A short summary', true),
            ]],
            ['admin', 'admin', 'Set Project Name', 'Set the human-friendly project name', "repo project-name {$r}", [
                $this->arg('name', 'Project name', 'My Project', true),
            ]],
            ['admin', 'admin', 'Rename Repository', 'Rename this repository', "repo rename {$r}", [
                $this->arg('new-name', 'New name', 'new-name', true),
            ]],
            ['admin', 'admin', 'Private Toggle', 'Make the repository private or public', "repo private {$r}", [
                $this->choiceArg('value', 'Private', ['true', 'false'], 'true'),
            ]],
            ['admin', 'admin', 'Hidden Toggle', 'Hide or unhide the repository from listings', "repo hidden {$r}", [
                $this->choiceArg('value', 'Hidden', ['true', 'false'], 'true'),
            ]],
            ['admin', 'admin', 'Mirror Toggle', 'Set or get the mirror property', "repo mirror {$r}", [
                $this->choiceArg('value', 'Mirror', ['true', 'false'], 'true'),
            ]],
            ['admin', 'admin', 'Require Signatures', 'Require verified signed commits on push', "repo signatures {$r}", [
                $this->choiceArg('value', 'Required', ['true', 'false'], 'true'),
            ]],
            ['admin', 'admin', 'Delete Repository', 'Permanently delete this repository', "repo delete {$r}", []],
            ['admin', 'admin', 'List Collaborators', 'List users with access to this repository', "repo collab list {$r}", []],
            ['admin', 'admin', 'Add Collaborator', 'Grant read, write, or admin access to a user', "repo collab add {$r}", [
                $this->arg('username', 'Username', 'alice', true),
                $this->choiceArg('level', 'Access level', ['read-only', 'read-write', 'admin-access'], 'read-write'),
            ]],
            ['admin', 'admin', 'Remove Collaborator', "Revoke a user's access", "repo collab remove {$r}", [
                $this->arg('username', 'Username', 'alice', true),
            ]],
            ['admin', 'admin', 'Default Branch', 'Set the default branch', "repo branch default {$r}", [
                $this->arg('branch', 'Branch', 'main', true),
            ]],
            ['admin', 'admin', 'Protect Branch', 'Add or update a branch protection rule', "repo branch protect {$r}", [
                $this->arg('pattern', 'Pattern', 'main', true),
                $this->boolOpt('no-force', 'Block force-push', '--no-force', false),
                $this->boolOpt('no-delete', 'Block deletion', '--no-delete', false),
                $this->boolOpt('admins-only', 'Admins only', '--admins-only', false),
            ]],
            ['admin', 'admin', 'Unprotect Branch', 'Remove a branch protection rule', "repo branch unprotect {$r}", [
                $this->arg('pattern', 'Pattern', 'main', true),
            ]],
            ['admin', 'admin', 'Delete Branch', 'Delete a branch', "repo branch delete {$r}", [
                $this->arg('branch', 'Branch', 'feature/x', true),
            ]],
            ['admin', 'admin', 'Delete Tag', 'Delete a tag', "repo tag delete {$r}", [
                $this->arg('tag', 'Tag', 'v1.0.0', true),
            ]],
            ['admin', 'admin', 'List Webhooks', 'List configured HTTP webhook endpoints', "repo webhook list {$r}", []],
            ['admin', 'admin', 'Create Webhook', 'Register an HTTP webhook endpoint', "repo webhook create {$r}", [
                $this->arg('url', 'Payload URL', 'https://example.com/hook', true),
                $this->opt('events', 'Events', '-e', 'push,pull_request', 'push'),
                $this->opt('secret', 'Secret', '-s', 'shared-secret'),
                $this->choiceOpt('content-type', 'Content type', '-c', ['json', 'form'], 'json'),
                $this->choiceOpt('active', 'Active', '-a', ['true', 'false'], 'true'),
            ]],
            ['admin', 'admin', 'Delete Webhook', 'Remove a webhook by id', "repo webhook delete {$r}", [
                $this->arg('webhook-id', 'Webhook id', '42', true),
            ]],
        ];

        $canWrite = $level->canWrite();
        $isAdmin = $level->isAdmin();

        $commands = [];
        foreach ($all as [$category, $tier, $title, $description, $base, $fields]) {
            if (('write' === $tier && !$canWrite) || ('admin' === $tier && !$isAdmin)) {
                continue;
            }
            $remote = $this->renderRemote($base, $fields);
            $commands[] = [
                'category' => $category,
                'tier' => $tier,
                'title' => $title,
                'description' => $description,
                'base' => $base,
                'fields' => $fields,
                'remote' => $remote,
                'command' => $prefix . ' ' . $remote,
            ];
        }

        return $commands;
    }

    /**
     * @return array{kind: string, key: string, label: string, placeholder: string, required: bool, default: string, flag: string, choices: list<string>}
     */
    private function arg(string $key, string $label, string $placeholder, bool $required = false, string $default = ''): array
    {
        return ['kind' => 'arg', 'key' => $key, 'label' => $label, 'placeholder' => $placeholder, 'required' => $required, 'default' => $default, 'flag' => '', 'choices' => []];
    }

    /**
     * @param list<string> $choices
     *
     * @return array{kind: string, key: string, label: string, placeholder: string, required: bool, default: string, flag: string, choices: list<string>}
     */
    private function choiceArg(string $key, string $label, array $choices, string $default): array
    {
        return ['kind' => 'choice-arg', 'key' => $key, 'label' => $label, 'placeholder' => '', 'required' => true, 'default' => $default, 'flag' => '', 'choices' => $choices];
    }

    /**
     * @return array{kind: string, key: string, label: string, placeholder: string, required: bool, default: string, flag: string, choices: list<string>}
     */
    private function opt(string $key, string $label, string $flag, string $placeholder, string $default = ''): array
    {
        return ['kind' => 'flag', 'key' => $key, 'label' => $label, 'placeholder' => $placeholder, 'required' => false, 'default' => $default, 'flag' => $flag, 'choices' => []];
    }

    /**
     * @param list<string> $choices
     *
     * @return array{kind: string, key: string, label: string, placeholder: string, required: bool, default: string, flag: string, choices: list<string>}
     */
    private function choiceOpt(string $key, string $label, string $flag, array $choices, string $default): array
    {
        return ['kind' => 'choice-flag', 'key' => $key, 'label' => $label, 'placeholder' => '', 'required' => false, 'default' => $default, 'flag' => $flag, 'choices' => $choices];
    }

    /**
     * @return array{kind: string, key: string, label: string, placeholder: string, required: bool, default: string, flag: string, choices: list<string>}
     */
    private function boolOpt(string $key, string $label, string $flag, bool $default): array
    {
        return ['kind' => 'bool', 'key' => $key, 'label' => $label, 'placeholder' => '', 'required' => false, 'default' => $default ? '1' : '', 'flag' => $flag, 'choices' => []];
    }

    /**
     * Render the default remote command (no prefix) from a base and its fields,
     * mirroring the client-side builder in repo-web.js.
     *
     * @param list<array{kind: string, key: string, label: string, placeholder: string, required: bool, default: string, flag: string, choices: list<string>}> $fields
     */
    private function renderRemote(string $base, array $fields): string
    {
        $out = $base;
        foreach ($fields as $f) {
            $value = $f['default'];
            switch ($f['kind']) {
                case 'bool':
                    if ('' !== $value) {
                        $out .= ' ' . $f['flag'];
                    }
                    break;
                case 'flag':
                case 'choice-flag':
                    if ('' !== $value) {
                        $out .= ' ' . $f['flag'] . ' ' . $this->shellQuote($value);
                    }
                    break;
                default: // arg, choice-arg
                    $out .= ' ' . ('' !== $value ? $this->shellQuote($value) : '<' . $f['key'] . '>');
            }
        }

        return $out;
    }

    private function shellQuote(string $value): string
    {
        if (1 === preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $value)) {
            return $value;
        }

        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    private function notFound(): Response
    {
        return new Response("Not Found\n", Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']);
    }

    private function domainUser(): ?User
    {
        $securityUser = $this->security->getUser();

        return $securityUser instanceof SecurityUser ? $securityUser->user() : null;
    }
}
