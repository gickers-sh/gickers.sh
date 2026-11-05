<?php

declare(strict_types=1);

namespace App\UI\Ssh\Help;

/**
 * Builds the SSH command tree used for `help`/`--help` rendering. The structure
 * mirrors upstream Soft Serve's cobra command tree so that help output matches
 * the Go server exactly.
 */
final class CommandCatalog
{
    private CommandSpec $root;

    public function __construct()
    {
        $this->root = $this->build();
    }

    public function root(): CommandSpec
    {
        return $this->root;
    }

    /**
     * Resolve a help/usage target from raw tokens (names or aliases). Returns
     * null when the path does not match a known command.
     *
     * @param list<string> $tokens
     */
    public function resolve(array $tokens): ?CommandSpec
    {
        return $this->root->find($tokens);
    }

    /**
     * Greedily descend the command tree by name/alias, mirroring cobra's
     * Command.Find: traversal stops at the first token that is a flag or is not
     * a known subcommand (that token and the rest are positional arguments).
     *
     * @param list<string> $tokens
     *
     * @return array{0: CommandSpec, 1: int} the deepest matched command and the number of tokens consumed
     */
    public function resolvePrefix(array $tokens): array
    {
        $node = $this->root;
        $consumed = 0;
        foreach ($tokens as $token) {
            if (str_starts_with($token, '-')) {
                break;
            }
            $next = null;
            foreach ($node->children as $child) {
                if ($child->name === $token || in_array($token, $child->aliases, true)) {
                    $next = $child;
                    break;
                }
            }
            if (null === $next) {
                break;
            }
            $node = $next;
            ++$consumed;
        }

        return [$node, $consumed];
    }

    /**
     * @param list<string>   $aliases
     * @param list<FlagSpec> $flags
     */
    private function leaf(string $use, string $short, string $long = '', array $aliases = [], array $flags = []): CommandSpec
    {
        $name = explode(' ', $use)[0];

        return new CommandSpec($name, $use, $short, $long, $aliases, $flags, true);
    }

    private function hiddenLeaf(string $use, string $short): CommandSpec
    {
        $name = explode(' ', $use)[0];

        return new CommandSpec($name, $use, $short, '', [], [], true, true);
    }

    /**
     * @param list<string> $aliases
     */
    private function group(string $name, string $short, array $aliases = []): CommandSpec
    {
        return new CommandSpec($name, $name, $short, '', $aliases, [], false);
    }

    private function build(): CommandSpec
    {
        $root = new CommandSpec('', '', '', 'Soft Serve is a self-hostable Git server for the command line.', [], [], false);

        $root->addChild($this->hiddenLeaf('git-upload-pack REPO', 'Git upload pack'));
        $root->addChild($this->hiddenLeaf('git-upload-archive REPO', 'Git upload archive'));
        $root->addChild($this->hiddenLeaf('git-receive-pack REPO', 'Git receive pack'));
        $root->addChild($this->hiddenLeaf('git-lfs-authenticate REPO OPERATION', 'Git LFS authenticate'));
        $root->addChild($this->hiddenLeaf('git-lfs-transfer REPO OPERATION', 'Git LFS transfer'));
        $root->addChild($this->leaf('help [command]', 'Help about any command'));
        $root->addChild($this->leaf('info', 'Show your info'));
        $root->addChild($this->leaf('jwt [repository1 repository2...]', 'Generate a JSON Web Token'));
        $root->addChild($this->pullRequests());
        $root->addChild($this->pubkey());
        $root->addChild($this->repo());
        $root->addChild($this->leaf('set-username USERNAME', 'Set your username'));
        $root->addChild($this->settings());
        $root->addChild($this->token());
        $root->addChild($this->user());

        return $root;
    }

    private function pullRequests(): CommandSpec
    {
        $pr = $this->group('pr', 'Manage pull requests', ['prs', 'pull-request', 'pull-requests']);
        $pr->addChild($this->leaf('close REPOSITORY NUMBER', 'Close a pull request without merging'));
        $pr->addChild($this->leaf('comment REPOSITORY NUMBER BODY...', 'Add a comment to a pull request'));
        $pr->addChild($this->leaf(
            'create REPOSITORY --head <[REPO:]BRANCH> [--base <BRANCH>] [--title <TITLE>] [--body <BODY>]',
            'Create a new pull request',
            '',
            [],
            [
                new FlagSpec('B', 'base', 'string', 'target branch to merge into (defaults to default branch)'),
                new FlagSpec('b', 'body', 'string', 'description/body of the pull request'),
                new FlagSpec('H', 'head', 'string', "source branch to merge from (e.g. 'my-feature' or 'my-fork:feature')"),
                new FlagSpec('t', 'title', 'string', 'title of the pull request'),
            ],
        ));
        $pr->addChild($this->leaf('delete REPOSITORY NUMBER', 'Permanently delete a pull request', '', ['rm']));
        $pr->addChild($this->leaf('diff REPOSITORY NUMBER', 'Show diff for a pull request'));
        $pr->addChild($this->leaf('list REPOSITORY', 'List pull requests for a repository', '', [], [
            new FlagSpec('s', 'status', 'string', 'filter by status (open, closed, merged, all)', '"open"'),
        ]));
        $pr->addChild($this->leaf('merge REPOSITORY NUMBER', 'Merge a pull request', '', [], [
            new FlagSpec('t', 'title', 'string', 'custom merge commit title'),
        ]));
        $pr->addChild($this->leaf('reopen REPOSITORY NUMBER', 'Reopen a closed pull request'));
        $pr->addChild($this->leaf('view REPOSITORY NUMBER', 'View details of a pull request'));

        return $pr;
    }

    private function pubkey(): CommandSpec
    {
        $pubkey = $this->group('pubkey', 'Manage your public keys', ['pubkeys', 'publickey', 'publickeys']);
        $pubkey->addChild($this->leaf('add AUTHORIZED_KEY', 'Add a public key'));
        $pubkey->addChild($this->leaf('list', 'List public keys', '', ['ls']));
        $pubkey->addChild($this->leaf('remove AUTHORIZED_KEY', 'Remove a public key'));

        return $pubkey;
    }

    private function settings(): CommandSpec
    {
        $settings = $this->group('settings', 'Manage server settings');
        $settings->addChild($this->leaf('allow-keyless [true|false]', 'Set or get allow keyless access to repositories'));
        $settings->addChild($this->leaf('anon-access [ACCESS_LEVEL]', 'Set or get the default access level for anonymous users'));

        return $settings;
    }

    private function token(): CommandSpec
    {
        $token = $this->group('token', 'Manage access tokens', ['access-token']);
        $token->addChild($this->leaf('create NAME', 'Create a new access token', '', [], [
            new FlagSpec(null, 'expires-in', 'string', 'Token expiration time (e.g. 1y, 3mo, 2w, 5d4h, 1h30m)'),
        ]));
        $token->addChild($this->leaf('delete ID', 'Delete an access token', '', ['rm', 'remove']));
        $token->addChild($this->leaf('list', 'List access tokens', '', ['ls']));

        return $token;
    }

    private function user(): CommandSpec
    {
        $user = $this->group('user', 'Manage users', ['users']);
        $user->addChild($this->leaf('add-pubkey USERNAME AUTHORIZED_KEY', 'Add a public key to a user'));
        $user->addChild($this->leaf('create USERNAME', 'Create a new user', '', [], [
            new FlagSpec('a', 'admin', '', 'make the user an admin'),
            new FlagSpec('k', 'key', 'string', 'add a public key to the user'),
        ]));
        $user->addChild($this->leaf('delete USERNAME', 'Delete a user'));
        $user->addChild($this->leaf('info USERNAME', 'Show information about a user'));
        $user->addChild($this->leaf('list', 'List users', '', ['ls']));
        $user->addChild($this->leaf('remove-pubkey USERNAME AUTHORIZED_KEY', 'Remove a public key from a user'));
        $user->addChild($this->leaf('set-admin USERNAME [true|false]', 'Make a user an admin'));
        $user->addChild($this->leaf('set-username USERNAME NEW_USERNAME', "Change a user's username"));

        return $user;
    }

    private function repo(): CommandSpec
    {
        $repo = $this->group('repo', 'Manage repositories', ['repos', 'repository', 'repositories']);

        $repo->addChild($this->leaf('blob REPOSITORY [REFERENCE] [PATH]', 'Print out the contents of file at path', '', ['cat', 'show'], [
            new FlagSpec('c', 'color', '', 'Colorize output'),
            new FlagSpec('l', 'linenumber', '', 'Print line numbers'),
            new FlagSpec('r', 'raw', '', 'Print raw contents'),
        ]));

        $branch = $this->group('branch', 'Manage repository branches');
        $branch->addChild($this->leaf('default REPOSITORY [BRANCH]', 'Set or get the default branch'));
        $branch->addChild($this->leaf('delete REPOSITORY BRANCH', 'Delete a branch', '', ['remove', 'rm', 'del']));
        $branch->addChild($this->leaf('list REPOSITORY', 'List repository branches'));
        $repo->addChild($branch);

        $collab = $this->group('collab', 'Manage collaborators', ['collabs', 'collaborator', 'collaborators']);
        $collab->addChild($this->leaf(
            'add REPOSITORY USERNAME [LEVEL]',
            'Add a collaborator to a repo',
            'Add a collaborator to a repo. LEVEL can be one of: no-access, read-only, read-write, or admin-access. Defaults to read-write.',
        ));
        $collab->addChild($this->leaf('list REPOSITORY', 'List collaborators for a repo'));
        $collab->addChild($this->leaf('remove REPOSITORY USERNAME', 'Remove a collaborator from a repo'));
        $repo->addChild($collab);

        $repo->addChild($this->leaf('commit repo SHA', 'Print out the contents of a diff', '', [], [
            new FlagSpec('c', 'color', '', 'Colorize output'),
            new FlagSpec('p', 'patch', '', 'Output patch only'),
        ]));
        $repo->addChild($this->leaf('create REPOSITORY', 'Create a new repository', '', [], $this->repoWriteFlags()));
        $repo->addChild($this->leaf('delete REPOSITORY', 'Delete a repository', '', ['del', 'remove', 'rm']));
        $repo->addChild($this->leaf('description REPOSITORY [DESCRIPTION]', 'Set or get the description for a repository', '', ['desc']));
        $repo->addChild($this->leaf('fork SOURCE [NEW_NAME]', 'Fork an existing repository', '', [], $this->repoWriteFlags()));
        $repo->addChild($this->leaf('hidden REPOSITORY [TRUE|FALSE]', 'Hide or unhide a repository', '', ['hide']));
        $repo->addChild($this->leaf('import REPOSITORY REMOTE', 'Import a new repository from remote', '', [], [
            new FlagSpec(null, 'lfs', '', 'pull Git LFS objects'),
            new FlagSpec(null, 'lfs-endpoint', 'string', 'set the Git LFS endpoint'),
            new FlagSpec('m', 'mirror', '', 'mirror the repository'),
            new FlagSpec('p', 'private', '', 'make the repository private'),
            new FlagSpec('d', 'description', 'string', 'set the repository description'),
            new FlagSpec('n', 'name', 'string', 'set the project name'),
            new FlagSpec('H', 'hidden', '', 'hide the repository from the UI'),
        ]));
        $repo->addChild($this->leaf('info REPOSITORY', 'Get information about a repository'));
        $repo->addChild($this->leaf('list', 'List repositories', '', ['ls'], [
            new FlagSpec('a', 'all', '', 'List all repositories'),
        ]));
        $repo->addChild($this->leaf('mirror REPOSITORY [true|false]', 'Set or get a repository mirror property'));
        $repo->addChild($this->pullRequests());
        $repo->addChild($this->leaf('private REPOSITORY [true|false]', 'Set or get a repository private property'));
        $repo->addChild($this->leaf('project-name REPOSITORY [NAME]', 'Set or get the project name for a repository', '', ['project']));
        $repo->addChild($this->leaf('rename REPOSITORY NEW_NAME', 'Rename an existing repository', '', ['mv', 'move']));

        $tag = $this->group('tag', 'Manage repository tags');
        $tag->addChild($this->leaf('delete REPOSITORY TAG', 'Delete a tag', '', ['remove', 'rm', 'del']));
        $tag->addChild($this->leaf('list REPOSITORY', 'List repository tags', '', ['ls']));
        $repo->addChild($tag);

        $repo->addChild($this->leaf('tree REPOSITORY [REFERENCE] [PATH]', 'Print repository tree at path'));
        $repo->addChild($this->webhook());

        return $repo;
    }

    /**
     * @return list<FlagSpec>
     */
    private function repoWriteFlags(): array
    {
        return [
            new FlagSpec('p', 'private', '', 'make the repository private'),
            new FlagSpec('d', 'description', 'string', 'set the repository description'),
            new FlagSpec('n', 'name', 'string', 'set the project name'),
            new FlagSpec('H', 'hidden', '', 'hide the repository from the UI'),
        ];
    }

    private function webhook(): CommandSpec
    {
        $events = 'events to trigger the webhook, available events are (branch_tag_create, branch_tag_delete, collaborator, push, repository, repository_visibility_change)';

        $webhook = $this->group('webhook', 'Manage repository webhooks', ['webhooks']);
        $webhook->addChild($this->leaf('create REPOSITORY URL', 'Create a repository webhook', '', [], [
            new FlagSpec('a', 'active', '', 'whether the webhook is active', 'true'),
            new FlagSpec('c', 'content-type', 'json', 'content type of the webhook payload, can be either json or `form`', '"json"'),
            new FlagSpec('e', 'events', 'strings', $events),
            new FlagSpec('s', 'secret', 'string', 'secret to sign the webhook payload'),
        ]));
        $webhook->addChild($this->leaf('delete REPOSITORY WEBHOOK_ID', 'Delete a repository webhook'));

        $deliveries = $this->group('deliveries', 'Manage webhook deliveries', ['delivery', 'deliver']);
        $deliveries->addChild($this->leaf('get REPOSITORY WEBHOOK_ID DELIVERY_ID', 'Get a webhook delivery'));
        $deliveries->addChild($this->leaf('list REPOSITORY WEBHOOK_ID', 'List webhook deliveries'));
        $deliveries->addChild($this->leaf('redeliver REPOSITORY WEBHOOK_ID DELIVERY_ID', 'Redeliver a webhook delivery'));
        $webhook->addChild($deliveries);

        $webhook->addChild($this->leaf('list REPOSITORY', 'List repository webhooks'));
        $webhook->addChild($this->leaf('update REPOSITORY WEBHOOK_ID', 'Update a repository webhook', '', [], [
            new FlagSpec('a', 'active', 'string', 'whether the webhook is active'),
            new FlagSpec('c', 'content-type', 'json', 'content type of the webhook payload, can be either json or `form`'),
            new FlagSpec('e', 'events', 'strings', $events),
            new FlagSpec('s', 'secret', 'string', 'secret to sign the webhook payload'),
            new FlagSpec('u', 'url', 'string', 'webhook URL'),
        ]));

        return $webhook;
    }
}
