<?php

declare(strict_types=1);

namespace App\UI\Ssh\Help;

/**
 * Builds the SSH command tree used for `help`/`--help` rendering. The structure
 * provides comprehensive guidance on commands, scopes, arguments, and practical usage.
 */
final class CommandCatalog
{
    private CommandSpec $root;
    private CommandSpec $userRoot;

    public function __construct()
    {
        $this->root = $this->build(true);
        $this->userRoot = $this->build(false);
    }

    public function root(bool $isAdmin = true): CommandSpec
    {
        return $isAdmin ? $this->root : $this->userRoot;
    }

    /**
     * Restricted root command tree for unauthenticated SSH keys when self-service
     * signup is enabled: only `signup` is exposed.
     */
    public function restrictedRootForUnknownKey(): CommandSpec
    {
        $rootLong = <<<'DESC'
Codes.sh is a self-hostable Git server for the command line. Your SSH key is not yet recognized. If self-service registration is enabled on this server, register yourself with the 'signup' command below to start creating and hosting repositories.
DESC;
        $example = <<<'EX'
  # Unified self-service signup & client onboarding (recommended)
  {ssh} signup | bash             # Linux / macOS
  {ssh} signup | iex              # Windows (PowerShell)

  # Signup with a desired username in the installer wizard
  {ssh:<username>} signup | bash

  # Recover existing account with backup password
  {ssh} recover <username>
EX;

        $root = new CommandSpec('', '', '', $rootLong, [], [], false, example: $example);
        $root->addChild($this->signupSpec());
        $root->addChild($this->recoverSpec());

        return $root;
    }

    /**
     * Resolve a help/usage target from raw tokens (names or aliases). Returns
     * null when the path does not match a known command.
     *
     * @param list<string> $tokens
     */
    public function resolve(array $tokens, bool $isAdmin = true): ?CommandSpec
    {
        return $this->root($isAdmin)->find($tokens);
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
    public function resolvePrefix(array $tokens, bool $isAdmin = true): array
    {
        $node = $this->root($isAdmin);
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
    private function leaf(string $use, string $short, string $long = '', array $aliases = [], array $flags = [], string $example = ''): CommandSpec
    {
        $name = explode(' ', $use)[0];

        return new CommandSpec($name, $use, $short, $long, $aliases, $flags, true, false, $example);
    }

    private function hiddenLeaf(string $use, string $short): CommandSpec
    {
        $name = explode(' ', $use)[0];

        return new CommandSpec($name, $use, $short, '', [], [], true, true);
    }

    /**
     * @param list<string> $aliases
     */
    private function group(string $name, string $short, array $aliases = [], string $long = '', string $example = ''): CommandSpec
    {
        return new CommandSpec($name, $name, $short, $long, $aliases, [], false, false, $example);
    }

    private function build(bool $includeAdmin = true): CommandSpec
    {
        $rootLong = <<<'DESC'
Codes.sh is a self-hostable Git server (inspired by Soft Serve) for the command line. It provides Git repository hosting, fine-grained access control, branch protections, commit signature enforcement, and webhooks driven entirely via SSH, HTTP, and an interactive TUI.
DESC;
        $rootExample = <<<'EX'
  # Clone a repository over SSH
  git clone {ssh}/my-repo.git

  # Inspect your user identity and registered SSH keys
  {ssh} info

  # Browse and manage repositories
  {ssh} repo list
  {ssh} repo create my-project --private

  # Manage personal access tokens and public keys
  {ssh} pubkey list
  {ssh} token create "ci-pipeline" --expires-in 30d
EX;
        $root = new CommandSpec('', '', '', $rootLong, [], [], false, false, $rootExample);
        $root->addUi(new CommandUiSpec('explore', 'read', 'TUI Interactive', 'Open the repository in the interactive terminal TUI', '-t {repo}', [], 'repo', 1));
        $root->addUi(new CommandUiSpec('explore', 'read', 'Terminal TUI', 'Explore repositories interactively with the terminal TUI', '', [], 'global', 1));

        $root->addChild($this->hiddenLeaf('git-upload-pack REPO', 'Git upload pack'));
        $root->addChild($this->hiddenLeaf('git-upload-archive REPO', 'Git upload archive'));
        $root->addChild($this->hiddenLeaf('git-receive-pack REPO', 'Git receive pack'));
        $root->addChild($this->hiddenLeaf('git-lfs-authenticate REPO OPERATION', 'Git LFS authenticate'));
        $root->addChild($this->hiddenLeaf('git-lfs-transfer REPO OPERATION', 'Git LFS transfer'));

        $aboutLong = <<<'DESC'
Displays the server about / README document (from the .soft-serve repository) rendered in the terminal using Termstrap.

Scope:
  Public (accessible to all authenticated and anonymous callers).

Output:
  Renders Markdown to ANSI using the Termstrap terminal engine.
DESC;
        $aboutExample = <<<'EX'
  # Display server about page
  {ssh} about
EX;
        $root->addChild($this->leaf('about', 'Show the server README (About)', $aboutLong, [], [
            new FlagSpec('w', 'width', 'int', 'terminal width in columns (0 = auto-detect)'),
        ], $aboutExample)->withUi(
            category: 'explore',
            title: 'About Server',
            tier: 'read',
            description: 'Show the server README (About)',
            baseTemplate: 'about',
            fields: [FieldSpec::opt('width', 'Terminal width', '-w', '80')],
            scope: 'global',
            order: 2,
        ));

        $helpLong = <<<'DESC'
Help provides contextual guidance and usage manuals for any codes.sh command, command group, or nested subcommand.

Scope:
  Public (accessible to all users, including unregistered SSH keys).

Arguments:
  command    Command or hierarchical subcommand path to inspect (e.g. "repo", "repo branch protect", "token").
DESC;
        $helpExample = <<<'EX'
  # Display help for the root application
  {ssh} help

  # Display help for repository management
  {ssh} help repo

  # Display help for a specific nested subcommand
  {ssh} help repo branch protect
  {ssh} repo branch protect --help
EX;
        $root->addChild($this->leaf('help [command]', 'Help about any command', $helpLong, [], [], $helpExample)->withUi(
            category: 'explore',
            title: 'Help',
            tier: 'read',
            description: 'Help about any command',
            baseTemplate: 'help',
            fields: [FieldSpec::arg('command', 'Command path', 'repo branch')],
            scope: 'global',
            order: 3,
        ));

        $infoLong = <<<'DESC'
Displays identity information for the currently connected SSH session: username, global server administrator status, and all registered OpenSSH public keys.

Scope:
  Any authenticated user (requires a registered SSH key).

Output:
  Outputs key-value pairs and authorized keys list to stdout.
DESC;
        $infoExample = <<<'EX'
  # Check your account profile and keys
  {ssh} info
EX;
        $root->addChild($this->leaf('info', 'Show your info', $infoLong, [], [], $infoExample)->withUi(
            category: 'explore',
            title: 'User Info',
            tier: 'read',
            description: 'Display username, administrator status, and public keys',
            baseTemplate: 'info',
            fields: [],
            scope: 'global',
            order: 4,
        ));

        $root->addChild($this->pubkey());
        $root->addChild($this->repo());
        $root->addChild($this->web());

        $setUsernameLong = <<<'DESC'
Updates the username of your authenticated account.

Scope:
  Any authenticated user.

Arguments:
  USERNAME    New unique username (alphanumeric, hyphens, and underscores).

Notes:
  Renaming your account updates ownership on personal repositories and alters SSH clone URLs. Operation is silent on success (exit code 0).
DESC;
        $setUsernameExample = <<<'EX'
  # Change your account username
  {ssh} set-username alice
EX;
        $root->addChild($this->leaf('set-username USERNAME', 'Set your username', $setUsernameLong, [], [], $setUsernameExample)->withUi(
            category: 'identity',
            title: 'Set Username',
            tier: 'read',
            description: 'Updates the username of your authenticated account',
            baseTemplate: 'set-username',
            fields: [FieldSpec::arg('username', 'New username', 'alice', true)],
            scope: 'global',
            order: 6,
        ));

        $root->addChild($this->signupSpec());
        $root->addChild($this->recoverSpec());
        if ($includeAdmin) {
            $root->addChild($this->settings());
        }
        $root->addChild($this->token());
        if ($includeAdmin) {
            $root->addChild($this->user());
        }
        $root->addChild($this->wiki($includeAdmin));

        return $root;
    }

    private function wiki(bool $includeAdmin = true): CommandSpec
    {
        $wikiLong = <<<'DESC'
Browse, search, and manage the server-wide Wiki knowledge base. All users can list, search, and read Markdown wiki pages rendered in the terminal; server administrators can also create, update, and delete pages.

Scope:
  Public (read commands); Server Admin (set and delete commands).
DESC;
        $wikiExample = <<<'EX'
  # List all wiki pages
  {ssh} wiki list

  # Read a wiki page in the terminal
  {ssh} wiki show getting-started

  # Search across all wiki pages
  {ssh} wiki search "ssh key"
EX;
        $wiki = $this->group('wiki', 'Browse and manage the server wiki', [], $wikiLong, $wikiExample);

        $listLong = <<<'DESC'
Lists all wiki pages ordered by position, category, and title.

Scope:
  Public (accessible to all callers).

Flags:
  -c, --category string    Filter pages by category name.
DESC;
        $listExample = <<<'EX'
  # List all wiki pages
  {ssh} wiki list

  # Filter wiki pages by category
  {ssh} wiki list --category Guides
EX;
        $wiki->addChild($this->leaf('list', 'List all wiki pages', $listLong, ['ls'], [
            new FlagSpec('c', 'category', 'string', 'filter pages by category'),
        ], $listExample)->withUi(
            category: 'wiki',
            title: 'List Wiki Pages',
            tier: 'read',
            description: 'List all pages in the server wiki',
            baseTemplate: 'wiki list',
            fields: [FieldSpec::opt('category', 'Category filter', '-c', '')],
            scope: 'global',
            order: 60,
        ));

        $showLong = <<<'DESC'
Displays a wiki page rendered as ANSI Markdown in the terminal using Termstrap (or raw Markdown with --raw).

Scope:
  Public (accessible to all callers).

Arguments:
  SLUG    Unique slug of the wiki page to read.

Flags:
  -r, --raw          Print raw Markdown without terminal rendering.
  -w, --width int    Terminal width in columns (0 = auto-detect).
DESC;
        $showExample = <<<'EX'
  # Read a wiki page rendered in the terminal
  {ssh} wiki show getting-started

  # Print the raw Markdown of a wiki page
  {ssh} wiki show getting-started --raw
EX;
        $wiki->addChild($this->leaf('show SLUG', 'Display a wiki page', $showLong, ['view', 'get', 'cat', 'read'], [
            new FlagSpec('r', 'raw', '', 'print raw Markdown instead of terminal-rendered output'),
            new FlagSpec('w', 'width', 'int', 'terminal width in columns (0 = auto-detect)'),
        ], $showExample)->withUi(
            category: 'wiki',
            title: 'Show Wiki Page',
            tier: 'read',
            description: 'Read a wiki page rendered in your terminal',
            baseTemplate: 'wiki show',
            fields: [FieldSpec::arg('slug', 'Page slug', 'getting-started', true), FieldSpec::boolOpt('raw', 'Raw Markdown', '--raw', false)],
            scope: 'global',
            order: 61,
        ));

        $searchLong = <<<'DESC'
Searches wiki pages by keyword across title, slug, category, and Markdown content.

Scope:
  Public (accessible to all callers).

Arguments:
  QUERY    Keyword or phrase to search for.
DESC;
        $searchExample = <<<'EX'
  # Search wiki pages for deployment guides
  {ssh} wiki search deploy
EX;
        $wiki->addChild($this->leaf('search QUERY', 'Search wiki pages by keyword', $searchLong, ['grep', 'find'], [], $searchExample)->withUi(
            category: 'wiki',
            title: 'Search Wiki',
            tier: 'read',
            description: 'Search wiki pages by keyword or phrase',
            baseTemplate: 'wiki search',
            fields: [FieldSpec::arg('query', 'Search query', 'deploy', true)],
            scope: 'global',
            order: 62,
        ));

        if ($includeAdmin) {
            $setLong = <<<'DESC'
Creates or updates a wiki page identified by SLUG. Content can be passed as arguments or piped via standard input.

Scope:
  Server Admin.

Arguments:
  SLUG       Unique URL-friendly slug (e.g. "getting-started", "guides/deploy").
  CONTENT    Markdown body (or omit and pipe via stdin).

Flags:
  -c, --category string    Category name (defaults to General).
  -p, --position int       Display sort order position (defaults to 0).
  -t, --title string       Page title (defaults to first # Heading or formatted slug).
DESC;
            $setExample = <<<'EX'
  # Create or update a wiki page inline
  {ssh} wiki set getting-started "# Getting Started\n\nWelcome to codes.sh!" --title "Getting Started" --category "Guides"

  # Pipe a local Markdown file into the wiki
  cat docs/guide.md | {ssh} wiki set guides/deploy --title "Deployment Guide" --category "Operations"
EX;
            $wiki->addChild($this->leaf('set SLUG [CONTENT]', 'Create or update a wiki page', $setLong, ['edit', 'create', 'save', 'write'], [
                new FlagSpec('c', 'category', 'string', 'page category (defaults to General)'),
                new FlagSpec('p', 'position', 'int', 'display sort order position'),
                new FlagSpec('t', 'title', 'string', 'page title'),
            ], $setExample)->withUi(
                category: 'wiki',
                title: 'Set Wiki Page',
                tier: 'admin',
                description: 'Create or update a wiki page (Server Admin)',
                baseTemplate: 'wiki set',
                fields: [
                    FieldSpec::arg('slug', 'Page slug', 'getting-started', true),
                    FieldSpec::opt('title', 'Title', '-t', 'Getting Started'),
                    FieldSpec::opt('category', 'Category', '-c', 'General'),
                    FieldSpec::arg('content', 'Markdown content', '# Getting Started', true),
                ],
                scope: 'global',
                order: 63,
            ));

            $deleteLong = <<<'DESC'
Permanently deletes a wiki page by its slug.

Scope:
  Server Admin.

Arguments:
  SLUG    Slug of the wiki page to delete.
DESC;
            $deleteExample = <<<'EX'
  # Delete a wiki page
  {ssh} wiki delete old-guide
EX;
            $wiki->addChild($this->leaf('delete SLUG', 'Delete a wiki page', $deleteLong, ['rm', 'del', 'remove'], [], $deleteExample)->withUi(
                category: 'wiki',
                title: 'Delete Wiki Page',
                tier: 'admin',
                description: 'Delete a wiki page (Server Admin)',
                baseTemplate: 'wiki delete',
                fields: [FieldSpec::arg('slug', 'Page slug', 'old-guide', true)],
                scope: 'global',
                order: 64,
            ));
        }

        return $wiki;
    }

    private function web(): CommandSpec
    {
        $webLong = <<<'DESC'
Authenticate your browser against the web interface using your SSH key.

Scope:
  Any authenticated user.

Because your SSH key already proves who you are, `web login` prints a short-lived magic link. Opening it in your browser sets a session cookie, after which repository pages reveal the full command palette you are authorized for (admin-only actions appear on repositories you administer). `web logout` revokes every active browser session.
DESC;
        $webExample = <<<'EX'
  # Print a magic link to sign your browser in
  {ssh} web login

  # Revoke every active browser session
  {ssh} web logout
EX;
        $web = $this->group('web', 'Authenticate the web interface', [], $webLong, $webExample);
        $web->addChild($this->leaf('login', 'Log your browser in and open the web interface')->withUi(
            category: 'identity',
            title: 'SSH Web Login',
            tier: 'read',
            description: 'Zero password authentication. Mint a magic link with your SSH key to sign in.',
            baseTemplate: 'web login',
            fields: [],
            scope: 'global',
            order: 10,
        ));
        $web->addChild($this->leaf('logout', 'Sign out of every active browser session')->withUi(
            category: 'identity',
            title: 'SSH Web Logout',
            tier: 'read',
            description: 'Sign out of every active browser session',
            baseTemplate: 'web logout',
            fields: [],
            scope: 'global',
            order: 11,
        ));

        return $web;
    }

    private function signupSpec(): CommandSpec
    {
        $signupLong = <<<'DESC'
Registers your SSH key as a new user account on codes.sh, or configures your client OpenSSH setup (~/.ssh/config).

Enables automatic TTY allocation (RequestTTY auto) for proper terminal width, connection multiplexing (5ms execution), and per-user key binding.

Scope:
  Public (all SSH keys, registered or unregistered).

Arguments:
  USERNAME    Desired unique username for your account (or target account to configure).

Flags:
      --bash              force Bash installer script
  -k, --key string        register a specific public key instead of the key you connected with
      --key-path string   predefined private key path
      --ps1               force PowerShell installer script
  -r, --raw               print raw ~/.ssh/config snippet only
DESC;
        $signupExample = <<<'EX'
  # Unified self-service signup & client onboarding (recommended)
  {ssh} signup | bash             # Linux / macOS
  {ssh} signup | iex              # Windows (PowerShell)

  # Configure client for an existing user
  {ssh} setup | bash

  # Output raw ~/.ssh/config block
  {ssh} setup --raw >> ~/.ssh/config
EX;

        return $this->leaf('signup USERNAME', 'Register yourself as a new user with your SSH key', $signupLong, ['setup', 'config'], [
            new FlagSpec('', 'bash', 'bool', 'force Bash installer script'),
            new FlagSpec('k', 'key', 'string', 'register a specific public key instead of the key you connected with'),
            new FlagSpec('', 'key-path', 'string', 'predefined private key path'),
            new FlagSpec('', 'ps1', 'bool', 'force PowerShell installer script'),
            new FlagSpec('r', 'raw', 'bool', 'print raw ~/.ssh/config snippet only'),
        ], $signupExample)->withUi(
            category: 'identity',
            title: 'Sign Up',
            tier: 'read',
            description: 'Register yourself as a new user with your SSH key',
            baseTemplate: 'signup',
            fields: [FieldSpec::arg('username', 'Desired username', 'alice', true)],
            scope: 'global',
            order: 12,
        );
    }

    private function recoverSpec(): CommandSpec
    {
        $recoverLong = <<<'DESC'
Recovers account access when an SSH key has been lost, using your backup password. Associates the incoming SSH key with your existing user account so you can immediately resume using codes.sh.

Scope:
  Unregistered or recovery SSH keys. Requires a backup password to have been configured on the account.

Arguments:
  USERNAME    Your username.
DESC;
        $recoverExample = <<<'EX'
  # Recover your account and register this machine's SSH key
  {ssh} recover alice
EX;

        return $this->leaf('recover USERNAME', 'Recover account access with your backup password', $recoverLong, [], [
            new FlagSpec('k', 'key', 'string', 'public key to register instead of the one you connected with'),
        ], $recoverExample)->withUi(
            category: 'identity',
            title: 'Recover Account',
            tier: 'read',
            description: 'Recover account access with your backup password',
            baseTemplate: 'recover',
            fields: [FieldSpec::arg('username', 'Target username', 'alice', true)],
            scope: 'global',
            order: 13,
        );
    }


    private function pubkey(): CommandSpec
    {
        $pubkeyLong = <<<'DESC'
Manage OpenSSH public keys linked to your user account. Registered keys authenticate SSH CLI commands, interactive TUI browsing, and Git push/pull operations.

Scope:
  Any authenticated user (affects your own account only).
DESC;
        $pubkeyExample = <<<'EX'
  # List all keys registered to your account
  {ssh} pubkey list

  # Add a new SSH public key
  {ssh} pubkey add "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5... laptop"

  # Remove an obsolete SSH public key
  {ssh} pubkey remove "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5... old-key"
EX;
        $pubkey = $this->group('pubkey', 'Manage your public keys', ['pubkeys', 'publickey', 'publickeys'], $pubkeyLong, $pubkeyExample);

        $addLong = <<<'DESC'
Associates an additional OpenSSH public key with your user account.

Scope:
  Authenticated user.

Arguments:
  AUTHORIZED_KEY    OpenSSH public key string (e.g. "ssh-ed25519 AAAAC... comment" or "ssh-rsa AAAAB...").

Notes:
  Idempotent; if the key is already linked to your account, the command succeeds silently. Rejects keys already claimed by other accounts.
DESC;
        $addExample = <<<'EX'
  # Add an Ed25519 public key
  {ssh} pubkey add "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG... user@desktop"

  # Add an RSA public key
  {ssh} pubkey add "ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQ... laptop"
EX;
        $pubkey->addChild($this->leaf('add AUTHORIZED_KEY', 'Add a public key', $addLong, [], [], $addExample)->withUi(
            category: 'keys',
            title: 'Add Public Key',
            tier: 'read',
            description: 'Add a public key to your account',
            baseTemplate: 'pubkey add',
            fields: [FieldSpec::arg('key', 'Public Key', 'ssh-ed25519 AAA...', true)],
            scope: 'global',
            order: 21,
        ));

        $listLong = <<<'DESC'
Displays all OpenSSH public keys registered under your user account, including key algorithm, base64 payload, and optional comment.

Scope:
  Authenticated user.
DESC;
        $listExample = <<<'EX'
  # List all your authorized keys
  {ssh} pubkey list
EX;
        $pubkey->addChild($this->leaf('list', 'List public keys', $listLong, ['ls'], [], $listExample)->withUi(
            category: 'keys',
            title: 'List Public Keys',
            tier: 'read',
            description: 'List your registered public keys',
            baseTemplate: 'pubkey list',
            fields: [],
            scope: 'global',
            order: 20,
        ));

        $removeLong = <<<'DESC'
Unlinks an existing OpenSSH public key from your account.

Scope:
  Authenticated user.

Arguments:
  AUTHORIZED_KEY    Public key string or key material to remove.

Notes:
  Revokes immediate SSH access for the matching key pair. Ensure you retain at least one valid key on your account to prevent lockout. Silent on success.
DESC;
        $removeExample = <<<'EX'
  # Remove a key by its full public key line
  {ssh} pubkey remove "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG... user@desktop"
EX;
        $pubkey->addChild($this->leaf('remove AUTHORIZED_KEY', 'Remove a public key', $removeLong, [], [], $removeExample)->withUi(
            category: 'keys',
            title: 'Remove Public Key',
            tier: 'read',
            description: 'Remove a public key from your account',
            baseTemplate: 'pubkey remove',
            fields: [FieldSpec::arg('key', 'Public Key', 'ssh-ed25519 AAA...', true)],
            scope: 'global',
            order: 22,
        ));

        return $pubkey;
    }

    private function settings(): CommandSpec
    {
        $settingsLong = <<<'DESC'
Manage global codes.sh server configuration, anonymous access permissions, keyless cloning, and self-registration policies.

Scope:
  Server Admin only (hidden from non-admin users in command listings).
DESC;
        $settings = $this->group('settings', 'Manage server settings', [], $settingsLong);

        $allowKeylessLong = <<<'DESC'
Gets or sets whether users without an SSH key can clone or read public repositories.

Scope:
  Server Admin.

Arguments:
  value    Optional boolean ('true' or 'false'). If omitted, prints current setting.
DESC;
        $allowKeylessExample = <<<'EX'
  # Inspect current keyless access setting
  {ssh} settings allow-keyless

  # Enable keyless access
  {ssh} settings allow-keyless true

  # Disable keyless access
  {ssh} settings allow-keyless false
EX;
        $settings->addChild($this->leaf('allow-keyless [true|false]', 'Set or get allow keyless access to repositories', $allowKeylessLong, [], [], $allowKeylessExample)->withUi(
            category: 'settings',
            title: 'Allow Keyless Toggle',
            tier: 'admin',
            description: 'Set or get allow keyless access to repositories',
            baseTemplate: 'settings allow-keyless',
            fields: [FieldSpec::choiceArg('value', 'Enabled', ['true', 'false'], 'true')],
            scope: 'global',
            order: 40,
        ));

        $allowSignupLong = <<<'DESC'
Gets or sets whether unknown SSH keys connecting to the server are permitted to self-register via 'ssh codes.sh signup USERNAME'.

Scope:
  Server Admin.

Arguments:
  value    Optional boolean ('true' or 'false'). If omitted, prints current setting.
DESC;
        $allowSignupExample = <<<'EX'
  # Check if self-service signup is open
  {ssh} settings allow-signup

  # Open self-service signup
  {ssh} settings allow-signup true

  # Close self-service signup
  {ssh} settings allow-signup false
EX;
        $settings->addChild($this->leaf('allow-signup [true|false]', 'Set or get whether unregistered keys may self-register', $allowSignupLong, [], [], $allowSignupExample)->withUi(
            category: 'settings',
            title: 'Allow Signup Toggle',
            tier: 'admin',
            description: 'Set or get whether unregistered keys may self-register',
            baseTemplate: 'settings allow-signup',
            fields: [FieldSpec::choiceArg('value', 'Enabled', ['true', 'false'], 'true')],
            scope: 'global',
            order: 41,
        ));

        $anonAccessLong = <<<'DESC'
Gets or sets the default access level granted to anonymous (unauthenticated) users on public repositories.

Scope:
  Server Admin.

Arguments:
  ACCESS_LEVEL    Access level: 'no-access', 'read-only', 'read-write', or 'admin-access'. If omitted, prints current level.
DESC;
        $anonAccessExample = <<<'EX'
  # Inspect current anonymous access level
  {ssh} settings anon-access

  # Restrict anonymous users to read-only access
  {ssh} settings anon-access read-only

  # Completely forbid anonymous access
  {ssh} settings anon-access no-access
EX;
        $settings->addChild($this->leaf('anon-access [ACCESS_LEVEL]', 'Set or get the default access level for anonymous users', $anonAccessLong, [], [], $anonAccessExample)->withUi(
            category: 'settings',
            title: 'Anonymous Access Level',
            tier: 'admin',
            description: 'Set or get the default access level for anonymous users',
            baseTemplate: 'settings anon-access',
            fields: [FieldSpec::choiceArg('level', 'Access level', ['no-access', 'read-only', 'read-write', 'admin-access'], 'read-only')],
            scope: 'global',
            order: 42,
        ));

        $deployPolicyLong = <<<'DESC'
Gets or sets the server-wide Docker deployment authorization policy: 'admins-only', 'authorized', or 'repo-admins'.

Scope:
  Server Admin.

Arguments:
  POLICY    Deploy policy: 'admins-only', 'authorized', or 'repo-admins'. If omitted, prints current policy.
DESC;
        $deployPolicyExample = <<<'EX'
  # Inspect current deploy policy
  {ssh} settings deploy-policy

  # Allow explicitly authorized users/repositories to deploy
  {ssh} settings deploy-policy authorized
EX;
        $settings->addChild($this->leaf('deploy-policy [POLICY]', 'Set or get the server-wide deployment policy', $deployPolicyLong, [], [], $deployPolicyExample)->withUi(
            category: 'settings',
            title: 'Deploy Policy',
            tier: 'admin',
            description: 'Set or get the server-wide Docker deployment policy',
            baseTemplate: 'settings deploy-policy',
            fields: [FieldSpec::choiceArg('policy', 'Policy', ['admins-only', 'authorized', 'repo-admins'], 'admins-only')],
            scope: 'global',
            order: 43,
        ));

        return $settings;
    }

    private function token(): CommandSpec
    {
        $tokenLong = <<<'DESC'
Manage personal access tokens (PAT). Tokens can be used for automated scripts, CI/CD runners, and HTTP authentication.

Scope:
  Authenticated user.
DESC;
        $token = $this->group('token', 'Manage access tokens', ['access-token'], $tokenLong);

        $createLong = <<<'DESC'
Generates a new personal access token with optional lifetime expiration.

Scope:
  Authenticated user.

Arguments:
  NAME    Descriptive label identifying the token purpose (e.g. "ci-runner", "automation").

Flags:
  --expires-in string    Token lifetime duration (e.g. 1y, 3mo, 2w, 5d4h, 1h30m). Defaults to no expiration.

Notes:
  The secret token string is printed to stdout once upon creation. It is not stored in plaintext and cannot be retrieved again; store it securely. Status confirmation is written to stderr.
DESC;
        $createExample = <<<'EX'
  # Create a permanent access token
  {ssh} token create "github-actions"

  # Create a token expiring in 30 days
  {ssh} token create "ci-pipeline" --expires-in 30d

  # Create a short-lived token expiring in 2 hours
  {ssh} token create "deploy-job" --expires-in 2h
EX;
        $token->addChild($this->leaf('create NAME', 'Create a new access token', $createLong, [], [
            new FlagSpec(null, 'expires-in', 'string', 'Token expiration time (e.g. 1y, 3mo, 2w, 5d4h, 1h30m)'),
        ], $createExample)->withUi(
            category: 'tokens',
            title: 'Create Access Token',
            tier: 'read',
            description: 'Create a new access token',
            baseTemplate: 'token create',
            fields: [FieldSpec::arg('name', 'Token name', 'ci-bot', true), FieldSpec::opt('expires-in', 'Expiration', '--expires-in', '30d')],
            scope: 'global',
            order: 31,
        ));

        $deleteLong = <<<'DESC'
Permanently revokes a personal access token by its numeric identifier. Any future request using this token is denied immediately.

Scope:
  Authenticated user (token owner).

Arguments:
  ID    Numeric token identifier (see 'token list').
DESC;
        $deleteExample = <<<'EX'
  # Revoke access token #42
  {ssh} token delete 42
EX;
        $token->addChild($this->leaf('delete ID', 'Delete an access token', $deleteLong, ['rm', 'remove'], [], $deleteExample)->withUi(
            category: 'tokens',
            title: 'Delete Access Token',
            tier: 'read',
            description: 'Delete an access token',
            baseTemplate: 'token delete',
            fields: [FieldSpec::arg('token-id', 'Token ID', '1', true)],
            scope: 'global',
            order: 32,
        ));

        $listLong = <<<'DESC'
Displays all active personal access tokens for your account, listing numeric token ID, label name, creation timestamp, and expiration date.

Scope:
  Authenticated user.
DESC;
        $listExample = <<<'EX'
  # List all your access tokens
  {ssh} token list
EX;
        $token->addChild($this->leaf('list', 'List access tokens', $listLong, ['ls'], [], $listExample)->withUi(
            category: 'tokens',
            title: 'List Access Tokens',
            tier: 'read',
            description: 'List access tokens',
            baseTemplate: 'token list',
            fields: [],
            scope: 'global',
            order: 30,
        ));

        return $token;
    }

    private function user(): CommandSpec
    {
        $userLong = <<<'DESC'
Manage user accounts, assign server administrator privileges, and add or remove public keys for any user.

Scope:
  Server Admin only (hidden from non-admin users in command listings).
DESC;
        $user = $this->group('user', 'Manage users', ['users'], $userLong);

        $addPubkeyLong = <<<'DESC'
Adds an OpenSSH public key to a specified user account.

Scope:
  Server Admin.

Arguments:
  USERNAME          Target username.
  AUTHORIZED_KEY    OpenSSH public key string.
DESC;
        $addPubkeyExample = <<<'EX'
  # Add a public key to alice's account
  {ssh} user add-pubkey alice "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5... alice@laptop"
EX;
        $user->addChild($this->leaf('add-pubkey USERNAME AUTHORIZED_KEY', 'Add a public key to a user', $addPubkeyLong, [], [], $addPubkeyExample)->withUi(
            category: 'admin',
            title: 'Add User Public Key',
            tier: 'admin',
            description: 'Add an OpenSSH public key to a specified user account',
            baseTemplate: 'user add-pubkey',
            fields: [FieldSpec::arg('username', 'Target username', 'alice', true), FieldSpec::arg('key', 'Public Key', 'ssh-ed25519 AAA...', true)],
            scope: 'global',
            order: 55,
        ));

        $createLong = <<<'DESC'
Creates a new user account with optional server administrator status and an initial SSH public key.

Scope:
  Server Admin.

Arguments:
  USERNAME    Unique username for the new account.

Flags:
  -a, --admin         Grant global server administrator privileges to the user.
  -k, --key string    Add an initial OpenSSH public key for the user.
DESC;
        $createExample = <<<'EX'
  # Create a standard user
  {ssh} user create alice

  # Create an administrator with an initial public key
  {ssh} user create bob --admin -k "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5... bob@work"
EX;
        $user->addChild($this->leaf('create USERNAME', 'Create a new user', $createLong, [], [
            new FlagSpec('a', 'admin', '', 'make the user an admin'),
            new FlagSpec('k', 'key', 'string', 'add a public key to the user'),
        ], $createExample)->withUi(
            category: 'admin',
            title: 'Create User',
            tier: 'admin',
            description: 'Create a new user account',
            baseTemplate: 'user create',
            fields: [FieldSpec::arg('username', 'Username', 'bob', true), FieldSpec::opt('key', 'Public key', '-k', 'ssh-ed25519 ...'), FieldSpec::boolOpt('admin', 'Administrator', '-a', false)],
            scope: 'global',
            order: 52,
        ));

        $deleteLong = <<<'DESC'
Permanently deletes a user account, along with their registered public keys and access tokens.

Scope:
  Server Admin.

Arguments:
  USERNAME    Username to permanently delete.
DESC;
        $deleteExample = <<<'EX'
  # Delete a user account
  {ssh} user delete alice
EX;
        $user->addChild($this->leaf('delete USERNAME', 'Delete a user', $deleteLong, [], [], $deleteExample)->withUi(
            category: 'admin',
            title: 'Delete User',
            tier: 'admin',
            description: 'Delete a user account',
            baseTemplate: 'user delete',
            fields: [FieldSpec::arg('username', 'Username', 'bob', true)],
            scope: 'global',
            order: 53,
        ));

        $infoLong = <<<'DESC'
Displays detailed information about a user: username, administrator status, and all registered public keys.

Scope:
  Server Admin.

Arguments:
  USERNAME    Target username to inspect.
DESC;
        $infoExample = <<<'EX'
  # Inspect account details for user alice
  {ssh} user info alice
EX;
        $user->addChild($this->leaf('info USERNAME', 'Show information about a user', $infoLong, [], [], $infoExample)->withUi(
            category: 'admin',
            title: 'User Info',
            tier: 'admin',
            description: 'Show user info',
            baseTemplate: 'user info',
            fields: [FieldSpec::arg('username', 'Username', 'alice', true)],
            scope: 'global',
            order: 51,
        ));

        $listLong = <<<'DESC'
Displays all registered user accounts on the server, showing administrator status and count of registered public keys.

Scope:
  Server Admin.
DESC;
        $listExample = <<<'EX'
  # List all server users
  {ssh} user list
EX;
        $user->addChild($this->leaf('list', 'List users', $listLong, ['ls'], [], $listExample)->withUi(
            category: 'admin',
            title: 'List Users',
            tier: 'admin',
            description: 'List user accounts',
            baseTemplate: 'user list',
            fields: [],
            scope: 'global',
            order: 50,
        ));

        $removePubkeyLong = <<<'DESC'
Removes a registered OpenSSH public key from a specified user account.

Scope:
  Server Admin.

Arguments:
  USERNAME          Target username.
  AUTHORIZED_KEY    Public key string or material to unlink.
DESC;
        $removePubkeyExample = <<<'EX'
  # Remove a public key from alice's account
  {ssh} user remove-pubkey alice "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5... alice@laptop"
EX;
        $user->addChild($this->leaf('remove-pubkey USERNAME AUTHORIZED_KEY', 'Remove a public key from a user', $removePubkeyLong, [], [], $removePubkeyExample)->withUi(
            category: 'admin',
            title: 'Remove User Public Key',
            tier: 'admin',
            description: 'Remove a public key from a user account',
            baseTemplate: 'user remove-pubkey',
            fields: [FieldSpec::arg('username', 'Target username', 'alice', true), FieldSpec::arg('key', 'Public Key', 'ssh-ed25519 AAA...', true)],
            scope: 'global',
            order: 56,
        ));

        $setAdminLong = <<<'DESC'
Promotes a user to server administrator, or revokes administrator privileges.

Scope:
  Server Admin.

Arguments:
  USERNAME    Target username.
  value       Optional boolean ('true' or 'false'). If omitted, toggles admin status.
DESC;
        $setAdminExample = <<<'EX'
  # Promote user to administrator
  {ssh} user set-admin alice true

  # Demote user to standard account
  {ssh} user set-admin alice false
EX;
        $user->addChild($this->leaf('set-admin USERNAME [true|false]', 'Make a user an admin', $setAdminLong, [], [], $setAdminExample)->withUi(
            category: 'admin',
            title: 'Set Admin Status',
            tier: 'admin',
            description: 'Make a user an admin or revoke admin privileges',
            baseTemplate: 'user set-admin',
            fields: [FieldSpec::arg('username', 'Username', 'bob', true), FieldSpec::choiceArg('admin', 'Admin', ['true', 'false'], 'true')],
            scope: 'global',
            order: 54,
        ));

        $setUsernameLong = <<<'DESC'
Changes the username of an existing user account. Updates personal repository namespaces and paths.

Scope:
  Server Admin.

Arguments:
  USERNAME        Current username.
  NEW_USERNAME    New unique username.
DESC;
        $setUsernameExample = <<<'EX'
  # Rename a user account
  {ssh} user set-username alice alice-new
EX;
        $user->addChild($this->leaf('set-username USERNAME NEW_USERNAME', "Change a user's username", $setUsernameLong, [], [], $setUsernameExample)->withUi(
            category: 'admin',
            title: 'Change Username',
            tier: 'admin',
            description: "Change a user's username",
            baseTemplate: 'user set-username',
            fields: [FieldSpec::arg('username', 'Target username', 'alice', true), FieldSpec::arg('new-username', 'New username', 'alice-new', true)],
            scope: 'global',
            order: 57,
        ));

        $deployAllowLong = <<<'DESC'
Grants or revokes Docker deployment permissions for a user account under the 'authorized' deploy policy.

Scope:
  Server Admin.

Arguments:
  USERNAME    Target username.
  value       Optional boolean ('true' or 'false'). If omitted, prints current status.
DESC;
        $deployAllowExample = <<<'EX'
  # Allow user alice to deploy repositories
  {ssh} user deploy-allow alice true
EX;
        $user->addChild($this->leaf('deploy-allow USERNAME [true|false]', 'Set or get whether a user is allowed to deploy repositories', $deployAllowLong, [], [], $deployAllowExample)->withUi(
            category: 'admin',
            title: 'Allow User Deployments',
            tier: 'admin',
            description: 'Allow or disallow a user to deploy repositories',
            baseTemplate: 'user deploy-allow',
            fields: [FieldSpec::arg('username', 'Username', 'alice', true), FieldSpec::choiceArg('allowed', 'Allowed', ['true', 'false'], 'true')],
            scope: 'global',
            order: 58,
        ));

        $passwordLong = <<<'DESC'
Sets or updates the backup password for a user account. Used for account recovery and sudo-mode elevation.

Scope:
  Account Owner or Server Admin.

Arguments:
  USERNAME    Target username (defaults to current user).
DESC;
        $passwordExample = <<<'EX'
  # Set your own backup password
  {ssh} user password

  # Set backup password for another user (admin only)
  {ssh} user password alice
EX;
        $user->addChild($this->leaf('password [USERNAME]', 'Set or update backup password for a user', $passwordLong, [], [
            new FlagSpec('p', 'password', 'string', 'plain password'),
            new FlagSpec(null, 'stdin', 'bool', 'read password from stdin'),
        ], $passwordExample)->withUi(
            category: 'identity',
            title: 'Set Password',
            tier: 'write',
            description: 'Set or update backup password for a user',
            baseTemplate: 'user password',
            fields: [FieldSpec::arg('username', 'Target username', 'alice', false)],
            scope: 'global',
            order: 59,
        ));

        return $user;
    }

    private function repo(): CommandSpec
    {
        $repoLong = <<<'DESC'
Create, configure, inspect, and administer Git repositories hosted on codes.sh.

Scope:
  Permissions vary by command (from public read-only to repository admin / server admin).
DESC;
        $repoExample = <<<'EX'
  # List accessible repositories
  {ssh} repo list

  # Create a new private repository
  {ssh} repo create my-app --private -d "Core backend service"

  # View repository metadata and clone URLs
  {ssh} repo info my-app

  # Read a file directly from the repository
  {ssh} repo blob my-app main composer.json
EX;
        $repo = $this->group('repo', 'Manage repositories', ['repos', 'repository', 'repositories'], $repoLong, $repoExample);

        $blobLong = <<<'DESC'
Displays the content of a file at a specific Git reference. Supports syntax highlighting, line numbers, or raw verbatim bytes.

Scope:
  Read access to REPOSITORY required.

Arguments:
  REPOSITORY    Repository name.
  REFERENCE     Git reference (branch, tag, or commit SHA).
  PATH          Relative file path within the repository tree.

Flags:
  -c, --color         Colorize file content with syntax highlighting.
  -l, --linenumber    Display 1-based line numbers along the left margin.
  -r, --raw           Print verbatim raw file bytes without decoration or paging.
DESC;
        $blobExample = <<<'EX'
  # View a file with syntax highlighting and line numbers
  {ssh} repo blob my-app main src/Kernel.php -c -l

  # Extract raw file content for piping into a local file
  {ssh} repo blob my-app main composer.json -r > composer.json
EX;
        $repo->addChild($this->leaf('blob REPOSITORY [REFERENCE] [PATH]', 'Print out the contents of file at path', $blobLong, ['cat', 'show'], [
            new FlagSpec('c', 'color', '', 'Colorize output'),
            new FlagSpec('l', 'linenumber', '', 'Print line numbers'),
            new FlagSpec('r', 'raw', '', 'Print raw contents'),
        ], $blobExample)->withUi(
            category: 'files',
            title: 'Inspect File (Blob)',
            tier: 'read',
            description: 'Print file contents with optional highlighting and line numbers',
            baseTemplate: 'repo blob {repo}',
            fields: [
                FieldSpec::arg('reference', 'Reference', 'HEAD'),
                FieldSpec::arg('path', 'Path', 'README.md', true),
                FieldSpec::boolOpt('color', 'Syntax highlight', '-c', true),
                FieldSpec::boolOpt('lines', 'Line numbers', '-l', true),
            ],
            scope: 'repo',
            order: 5,
        ));

        $branchLong = <<<'DESC'
List, delete, configure the default branch, and enforce branch protection rules in a repository.

Scope:
  Read access for list; Collaborator (RW) for default/delete; Repository Admin for protection rules.
DESC;
        $branchExample = <<<'EX'
  # List branches
  {ssh} repo branch list my-app

  # Change default branch to main
  {ssh} repo branch default my-app main

  # Protect the main branch against force pushes
  {ssh} repo branch protect my-app main --no-force --no-delete
EX;
        $branch = $this->group('branch', 'Manage repository branches', [], $branchLong, $branchExample);

        $branchDefaultLong = <<<'DESC'
Gets the current default branch (symbolic HEAD), or updates it to BRANCH if provided. The default branch is checked out by default when users clone without specifying a branch.

Scope:
  Read access to inspect; Collaborator (RW) or Repository Admin to update.

Arguments:
  REPOSITORY    Repository name.
  BRANCH        Optional new default branch name (e.g. "main", "master").
DESC;
        $branchDefaultExample = <<<'EX'
  # View current default branch
  {ssh} repo branch default my-app

  # Set default branch to main
  {ssh} repo branch default my-app main
EX;
        $branch->addChild($this->leaf('default REPOSITORY [BRANCH]', 'Set or get the default branch', $branchDefaultLong, [], [], $branchDefaultExample)->withUi(
            category: 'admin',
            title: 'Default Branch',
            tier: 'admin',
            description: 'Set the default branch',
            baseTemplate: 'repo branch default {repo}',
            fields: [FieldSpec::arg('branch', 'Branch', 'main', true)],
            scope: 'repo',
            order: 25,
        ));

        $branchDeleteLong = <<<'DESC'
Deletes a remote branch ref. Rejects deletion if the branch is currently the default branch or if a branch protection rule prohibits deletion.

Scope:
  Collaborator (RW) or Repository Admin.

Arguments:
  REPOSITORY    Repository name.
  BRANCH        Branch name to delete (e.g. "feature-old").
DESC;
        $branchDeleteExample = <<<'EX'
  # Delete a merged feature branch
  {ssh} repo branch delete my-app feature-old
EX;
        $branch->addChild($this->leaf('delete REPOSITORY BRANCH', 'Delete a branch', $branchDeleteLong, ['remove', 'rm', 'del'], [], $branchDeleteExample)->withUi(
            category: 'admin',
            title: 'Delete Branch',
            tier: 'admin',
            description: 'Delete a branch',
            baseTemplate: 'repo branch delete {repo}',
            fields: [FieldSpec::arg('branch', 'Branch', 'feature/x', true)],
            scope: 'repo',
            order: 28,
        ));

        $branchListLong = <<<'DESC'
Displays all branch refs in the repository, marking the current default branch (HEAD).

Scope:
  Read access to REPOSITORY required.

Arguments:
  REPOSITORY    Repository name.
DESC;
        $branchListExample = <<<'EX'
  # List all branches in a repository
  {ssh} repo branch list my-app
EX;
        $branch->addChild($this->leaf('list REPOSITORY', 'List repository branches', $branchListLong, [], [], $branchListExample)->withUi(
            category: 'git',
            title: 'List Branches',
            tier: 'read',
            description: 'List all repository branches',
            baseTemplate: 'repo branch list {repo}',
            fields: [],
            scope: 'repo',
            order: 9,
        ));

        $branchProtectLong = <<<'DESC'
Enforces push safeguards on branches matching a glob pattern via the server pre-receive hook. Protects production branches against accidental force-pushes or deletion.

Scope:
  Repository Admin or Server Admin.

Arguments:
  REPOSITORY    Repository name.
  PATTERN       Branch glob pattern (e.g. "main", "release/*", "v*").

Flags:
  --admins-only    Only repository administrators and server admins may push to matching branches.
  --no-delete      Reject deletion of matching branches (git push origin :branch).
  --no-force       Reject force pushes and non-fast-forward updates (git push --force).
DESC;
        $branchProtectExample = <<<'EX'
  # Protect main branch against force-pushes and deletion
  {ssh} repo branch protect my-app main --no-force --no-delete

  # Protect all release branches against force-pushes
  {ssh} repo branch protect my-app "release/*" --no-force

  # Restrict pushes on all branches to administrators only
  {ssh} repo branch protect my-app "*" --admins-only
EX;
        $branch->addChild($this->leaf('protect REPOSITORY PATTERN', 'Add or update a branch protection rule', $branchProtectLong, [], [
            new FlagSpec(null, 'no-force', '', 'reject non-fast-forward (force) updates'),
            new FlagSpec(null, 'no-delete', '', 'reject deletion of matching refs'),
            new FlagSpec(null, 'admins-only', '', 'only administrators may update matching refs'),
        ], $branchProtectExample)->withUi(
            category: 'admin',
            title: 'Protect Branch',
            tier: 'admin',
            description: 'Add or update a branch protection rule',
            baseTemplate: 'repo branch protect {repo}',
            fields: [
                FieldSpec::arg('pattern', 'Pattern', 'main', true),
                FieldSpec::boolOpt('no-force', 'Block force-push', '--no-force', false),
                FieldSpec::boolOpt('no-delete', 'Block deletion', '--no-delete', false),
                FieldSpec::boolOpt('admins-only', 'Admins only', '--admins-only', false),
            ],
            scope: 'repo',
            order: 26,
        ));

        $branchUnprotectLong = <<<'DESC'
Removes a previously configured branch protection rule matching PATTERN.

Scope:
  Repository Admin or Server Admin.

Arguments:
  REPOSITORY    Repository name.
  PATTERN       Existing glob pattern to remove (e.g. "main", "release/*").
DESC;
        $branchUnprotectExample = <<<'EX'
  # Remove protection from release branches
  {ssh} repo branch unprotect my-app "release/*"
EX;
        $branch->addChild($this->leaf('unprotect REPOSITORY PATTERN', 'Remove a branch protection rule', $branchUnprotectLong, [], [], $branchUnprotectExample)->withUi(
            category: 'admin',
            title: 'Unprotect Branch',
            tier: 'admin',
            description: 'Remove a branch protection rule',
            baseTemplate: 'repo branch unprotect {repo}',
            fields: [FieldSpec::arg('pattern', 'Pattern', 'main', true)],
            scope: 'repo',
            order: 27,
        ));
        $repo->addChild($branch);

        $collabLong = <<<'DESC'
Grant, inspect, or revoke access permissions for users on a specific repository.

Scope:
  Repository Admin or Server Admin.
DESC;
        $collabExample = <<<'EX'
  # List collaborators
  {ssh} repo collab list my-app

  # Add a collaborator with read-write access
  {ssh} repo collab add my-app bob read-write

  # Remove a collaborator
  {ssh} repo collab remove my-app bob
EX;
        $collab = $this->group('collab', 'Manage collaborators', ['collabs', 'collaborator', 'collaborators'], $collabLong, $collabExample);

        $collabAddLong = <<<'DESC'
Add a collaborator to a repo. LEVEL can be one of: no-access, read-only, read-write, or admin-access. Defaults to read-write.

Scope:
  Repository Admin or Server Admin.

Arguments:
  REPOSITORY    Repository name.
  USERNAME      Username of the collaborator.
  LEVEL         Access level: 'read-only', 'read-write', 'admin-access', or 'no-access' (default: read-write).

Notes:
  - read-only: clone, pull, and inspect repository.
  - read-write: push commits and non-protected branches.
  - admin-access: manage collaborators, branch protection, webhooks, and settings.
  - no-access: revokes all access on private repositories.
DESC;
        $collabAddExample = <<<'EX'
  # Add a collaborator with default read-write access
  {ssh} repo collab add my-app bob

  # Add a reviewer with read-only access
  {ssh} repo collab add my-app charlie read-only

  # Add a co-maintainer with admin access
  {ssh} repo collab add my-app lead-dev admin-access
EX;
        $collab->addChild($this->leaf(
            'add REPOSITORY USERNAME [LEVEL]',
            'Add a collaborator to a repo',
            $collabAddLong,
            [],
            [],
            $collabAddExample,
        )->withUi(
            category: 'admin',
            title: 'Add Collaborator',
            tier: 'admin',
            description: 'Grant read, write, or admin access to a user',
            baseTemplate: 'repo collab add {repo}',
            fields: [
                FieldSpec::arg('username', 'Username', 'alice', true),
                FieldSpec::choiceArg('level', 'Access level', ['read-only', 'read-write', 'admin-access'], 'read-write'),
            ],
            scope: 'repo',
            order: 23,
        ));

        $collabListLong = <<<'DESC'
Displays all collaborators explicitly assigned to the repository alongside their active access level.

Scope:
  Read access to REPOSITORY required.

Arguments:
  REPOSITORY    Repository name.
DESC;
        $collabListExample = <<<'EX'
  # List all collaborators on a repository
  {ssh} repo collab list my-app
EX;
        $collab->addChild($this->leaf('list REPOSITORY', 'List collaborators for a repo', $collabListLong, [], [], $collabListExample)->withUi(
            category: 'admin',
            title: 'List Collaborators',
            tier: 'admin',
            description: 'List users with access to this repository',
            baseTemplate: 'repo collab list {repo}',
            fields: [],
            scope: 'repo',
            order: 22,
        ));

        $collabRemoveLong = <<<'DESC'
Removes explicit collaboration permissions for a user from the repository.

Scope:
  Repository Admin or Server Admin.

Arguments:
  REPOSITORY    Repository name.
  USERNAME      Collaborator username to remove.
DESC;
        $collabRemoveExample = <<<'EX'
  # Remove a collaborator
  {ssh} repo collab remove my-app bob
EX;
        $collab->addChild($this->leaf('remove REPOSITORY USERNAME', 'Remove a collaborator from a repo', $collabRemoveLong, [], [], $collabRemoveExample)->withUi(
            category: 'admin',
            title: 'Remove Collaborator',
            tier: 'admin',
            description: "Revoke a user's access",
            baseTemplate: 'repo collab remove {repo}',
            fields: [FieldSpec::arg('username', 'Username', 'alice', true)],
            scope: 'repo',
            order: 24,
        ));
        $repo->addChild($collab);

        $commitLong = <<<'DESC'
Displays commit details: author, committer, dates, commit message, and changed files. Can output full unified diff or colorized changes.

Scope:
  Read access to repo required.

Arguments:
  repo    Repository name.
  SHA     Full or abbreviated Git commit SHA hash.

Flags:
  -c, --color    Colorize commit diff output.
  -p, --patch    Output unified patch only (suitable for 'git apply').
DESC;
        $commitExample = <<<'EX'
  # View commit details and diff
  {ssh} repo commit my-app 8a3f12c

  # View colorized commit diff
  {ssh} repo commit my-app HEAD -c

  # Export commit as patch file
  {ssh} repo commit my-app 8a3f12c -p > fix.patch
EX;
        $repo->addChild($this->leaf('commit repo SHA', 'Print out the contents of a diff', $commitLong, [], [
            new FlagSpec('c', 'color', '', 'Colorize output'),
            new FlagSpec('p', 'patch', '', 'Output patch only'),
        ], $commitExample)->withUi(
            category: 'git',
            title: 'Commit Log',
            tier: 'read',
            description: 'View commit history and authors',
            baseTemplate: 'repo commit {repo}',
            fields: [FieldSpec::arg('ref', 'Commit / ref', 'HEAD')],
            scope: 'repo',
            order: 7,
        ));

        $createLong = <<<'DESC'
Initializes a new bare Git repository on codes.sh. The authenticated creator automatically becomes the repository owner with full admin permissions.

Scope:
  Authenticated user.

Arguments:
  REPOSITORY    Repository name (e.g. "my-project").

Flags:
  -d, --description string    Set the repository description shown in listings and TUI.
  -H, --hidden                Hide the repository from public listings in TUI and web browser.
  -n, --name string           Set a human-readable display title for the project.
  -p, --private               Make the repository private (accessible only to collaborators and admins).

Notes:
  Outputs creation confirmation notice to stderr and the SSH clone URL to stdout.
DESC;
        $createExample = <<<'EX'
  # Create a public repository
  {ssh} repo create my-app

  # Create a private repository with description and custom title
  {ssh} repo create backend-api --private -d "Internal microservice" -n "Backend API"

  # Create a hidden repository
  {ssh} repo create secret-tool --private --hidden
EX;
        $repo->addChild($this->leaf('create REPOSITORY', 'Create a new repository', $createLong, [], $this->repoWriteFlags(), $createExample)->withUi(
            category: 'repos',
            title: 'Create Repository',
            tier: 'write',
            description: 'Create a new repository',
            baseTemplate: 'repo create',
            fields: [
                FieldSpec::arg('name', 'Repository name', 'my-repo', true),
                FieldSpec::boolOpt('private', 'Private repository', '-p', false),
                FieldSpec::opt('description', 'Description', '-d', 'A short summary'),
            ],
            scope: 'global',
            order: 1,
        ));

        $deleteLong = <<<'DESC'
Permanently deletes a bare Git repository from disk, including all branches, tags, collaborator permissions, branch protection rules, and webhooks. Irreversible.

Scope:
  Repository Owner or Server Admin.

Arguments:
  REPOSITORY    Repository name to permanently delete.
DESC;
        $deleteExample = <<<'EX'
  # Delete a repository
  {ssh} repo delete obsolete-project
EX;
        $repo->addChild($this->leaf('delete REPOSITORY', 'Delete a repository', $deleteLong, ['del', 'remove', 'rm'], [], $deleteExample)->withUi(
            category: 'admin',
            title: 'Delete Repository',
            tier: 'admin',
            description: 'Permanently delete this repository',
            baseTemplate: 'repo delete {repo}',
            fields: [],
            scope: 'repo',
            order: 21,
        ));

        $descriptionLong = <<<'DESC'
Gets the repository description, or updates it if DESCRIPTION is provided.

Scope:
  Read access to inspect; Repository Admin to update.

Arguments:
  REPOSITORY     Repository name.
  DESCRIPTION    Optional new description text. If omitted, prints the current description.
DESC;
        $descriptionExample = <<<'EX'
  # View current description
  {ssh} repo description my-app

  # Set new description
  {ssh} repo description my-app "High-performance distributed storage engine"
EX;
        $repo->addChild($this->leaf('description REPOSITORY [DESCRIPTION]', 'Set or get the description for a repository', $descriptionLong, ['desc'], [], $descriptionExample)->withUi(
            category: 'admin',
            title: 'Set Description',
            tier: 'admin',
            description: 'Set the repository description',
            baseTemplate: 'repo description {repo}',
            fields: [FieldSpec::arg('text', 'Description', 'A short summary', true)],
            scope: 'repo',
            order: 14,
        ));

        $diffLong = <<<'DESC'
Generates a unified diff comparing two Git commits, branches, or tags. If TO is omitted, displays the changeset introduced by commit FROM relative to its parent.

Scope:
  Read access to REPOSITORY required.

Arguments:
  REPOSITORY    Repository name.
  FROM          Base reference or commit SHA.
  TO            Optional target reference or commit SHA to compare against.
DESC;
        $diffExample = <<<'EX'
  # View diff between two branches
  {ssh} repo diff my-app main feature-branch

  # View changes between two release tags
  {ssh} repo diff my-app v1.0.0 v1.1.0

  # View changes introduced by a specific commit
  {ssh} repo diff my-app HEAD~1
EX;
        $repo->addChild($this->leaf('diff REPOSITORY FROM [TO]', 'Print the diff between two references', $diffLong, [], [], $diffExample)->withUi(
            category: 'git',
            title: 'Inspect Diff',
            tier: 'read',
            description: 'Inspect changes between two references',
            baseTemplate: 'repo diff {repo}',
            fields: [
                FieldSpec::arg('from', 'From', 'HEAD~1', true, 'HEAD~1'),
                FieldSpec::arg('to', 'To', 'HEAD', false, 'HEAD'),
            ],
            scope: 'repo',
            order: 8,
        ));

        $forkLong = <<<'DESC'
Creates a server-side fork of an existing Git repository. You become the owner and administrator of the newly created fork.

Scope:
  Read access to SOURCE required; authenticated user.

Arguments:
  SOURCE      Source repository name to fork from.
  NEW_NAME    Optional destination repository name (defaults to 'SOURCE-fork').

Flags:
  -d, --description string    Set custom description for the fork.
  -H, --hidden                Hide fork from public listings.
  -n, --name string           Set display name for the fork.
  -p, --private               Make the fork private.
DESC;
        $forkExample = <<<'EX'
  # Fork a repository with default destination name
  {ssh} repo fork upstream-project

  # Fork into a custom repository name and make it private
  {ssh} repo fork upstream-project my-custom-fork --private
EX;
        $repo->addChild($this->leaf('fork SOURCE [NEW_NAME]', 'Fork an existing repository', $forkLong, [], $this->repoWriteFlags(), $forkExample)->withUi(
            category: 'write',
            title: 'Fork Repository',
            tier: 'write',
            description: 'Create a personal fork of this repository',
            baseTemplate: 'repo fork {repo}',
            fields: [FieldSpec::arg('new-name', 'New name', 'my-fork')],
            scope: 'repo',
            order: 12,
        ));

        $grepLong = <<<'DESC'
Searches tracked repository files using Git regular expressions at the specified Git reference.

Scope:
  Read access to REPOSITORY required.

Arguments:
  REPOSITORY    Repository name.
  PATTERN       Search pattern or regular expression.
  REFERENCE     Optional Git reference (branch, tag, or commit SHA; default: HEAD).
DESC;
        $grepExample = <<<'EX'
  # Search for a function name across the default branch
  {ssh} repo grep my-app "function execute"

  # Search on a specific branch
  {ssh} repo grep my-app "TODO:" develop

  # Search using a regex pattern on a release tag
  {ssh} repo grep my-app "class [A-Z][a-zA-Z]+" v2.0.0
EX;
        $repo->addChild($this->leaf('grep REPOSITORY PATTERN [REFERENCE]', 'Search repository contents', $grepLong, ['search'], [], $grepExample)->withUi(
            category: 'files',
            title: 'Code Search (Grep)',
            tier: 'read',
            description: 'Search a regex pattern across repository files',
            baseTemplate: 'repo grep {repo}',
            fields: [
                FieldSpec::arg('pattern', 'Pattern', 'TODO|FIXME', true),
                FieldSpec::arg('reference', 'Reference', 'HEAD'),
            ],
            scope: 'repo',
            order: 6,
        ));

        $hiddenLong = <<<'DESC'
Gets or sets whether the repository is hidden from the interactive TUI repository list and public web catalog. Does not alter Git clone or push permissions.

Scope:
  Read access to inspect; Repository Admin to update.

Arguments:
  REPOSITORY    Repository name.
  value         Optional boolean ('true' or 'false'). If omitted, prints current state.
DESC;
        $hiddenExample = <<<'EX'
  # Check if repository is hidden
  {ssh} repo hidden my-app

  # Hide repository from listings
  {ssh} repo hidden my-app true

  # Unhide repository
  {ssh} repo hidden my-app false
EX;
        $repo->addChild($this->leaf('hidden REPOSITORY [TRUE|FALSE]', 'Hide or unhide a repository', $hiddenLong, ['hide'], [], $hiddenExample)->withUi(
            category: 'admin',
            title: 'Hidden Toggle',
            tier: 'admin',
            description: 'Hide or unhide the repository from listings',
            baseTemplate: 'repo hidden {repo}',
            fields: [FieldSpec::choiceArg('value', 'Hidden', ['true', 'false'], 'true')],
            scope: 'repo',
            order: 18,
        ));

        $importLong = <<<'DESC'
Imports an external Git repository from a remote URL over HTTP, HTTPS, or Git protocols. Can configure full mirror synchronization and download Git LFS objects.

Scope:
  Authenticated user.

Arguments:
  REPOSITORY    Target repository name on codes.sh.
  REMOTE        Remote Git repository URL (https://... or git://...).

Flags:
  -d, --description string     Set the repository description.
  -H, --hidden                 Hide repository from UI listings.
      --lfs                    Download and store Git LFS objects from remote.
      --lfs-endpoint string    Custom Git LFS server endpoint URL.
  -m, --mirror                 Set up as a mirror (fetches all branches, tags, and refs).
  -n, --name string            Set display name for the repository.
  -p, --private                Make the imported repository private.
DESC;
        $importExample = <<<'EX'
  # Import a repository from GitHub
  {ssh} repo import my-lib https://github.com/org/my-lib.git

  # Mirror an upstream repository with all branches and tags
  {ssh} repo import upstream-mirror https://gitlab.com/group/repo.git --mirror

  # Import with Git LFS assets and make private
  {ssh} repo import game-assets https://github.com/org/game.git --lfs --private
EX;
        $repo->addChild($this->leaf('import REPOSITORY REMOTE', 'Import a new repository from remote', $importLong, [], [
            new FlagSpec(null, 'lfs', '', 'pull Git LFS objects'),
            new FlagSpec(null, 'lfs-endpoint', 'string', 'set the Git LFS endpoint'),
            new FlagSpec('m', 'mirror', '', 'mirror the repository'),
            new FlagSpec('p', 'private', '', 'make the repository private'),
            new FlagSpec('d', 'description', 'string', 'set the repository description'),
            new FlagSpec('n', 'name', 'string', 'set the project name'),
            new FlagSpec('H', 'hidden', '', 'hide the repository from the UI'),
        ], $importExample)->withUi(
            category: 'write',
            title: 'Import Mirror',
            tier: 'write',
            description: 'Import or refresh this repository from a remote',
            baseTemplate: 'repo import {repo}',
            fields: [FieldSpec::arg('remote', 'Remote URL', 'https://github.com/owner/repo.git', true)],
            scope: 'repo',
            order: 13,
        ));

        $infoRepoLong = <<<'DESC'
Displays detailed metadata and statistics for a repository: visibility (public/private), hidden state, default branch, commit count, branch count, tag count, and clone URLs.

Scope:
  Read access to REPOSITORY required.

Arguments:
  REPOSITORY    Repository name to inspect.
DESC;
        $infoRepoExample = <<<'EX'
  # Show metadata for a repository
  {ssh} repo info my-app
EX;
        $repo->addChild($this->leaf('info REPOSITORY', 'Get information about a repository', $infoRepoLong, [], [], $infoRepoExample)->withUi(
            category: 'explore',
            title: 'Repository Info',
            tier: 'read',
            description: 'Display metadata, default branch, disk usage and statistics',
            baseTemplate: 'repo info {repo}',
            fields: [],
            scope: 'repo',
            order: 2,
        ));

        $listRepoLong = <<<'DESC'
Lists repositories available on the server. Non-admin users see public repositories and private repositories where they are collaborators. Server administrators with --all see all repositories.

Scope:
  Public / Authenticated.

Flags:
  -a, --all    List all repositories including private and hidden ones (admins only).
DESC;
        $listRepoExample = <<<'EX'
  # List all accessible repositories
  {ssh} repo list

  # List all server repositories including hidden/private (admins only)
  {ssh} repo list -a
EX;
        $repo->addChild($this->leaf('list', 'List repositories', $listRepoLong, ['ls'], [
            new FlagSpec('a', 'all', '', 'List all repositories'),
        ], $listRepoExample)->withUi(
            category: 'repos',
            title: 'List Repositories',
            tier: 'read',
            description: 'List accessible repositories',
            baseTemplate: 'repo list',
            fields: [FieldSpec::boolOpt('all', 'All repositories (admin)', '-a', false)],
            scope: 'global',
            order: 1,
        ));

        $mirrorLong = <<<'DESC'
Gets or sets whether the repository acts as an exact Git mirror of an upstream remote.

Scope:
  Read access to inspect; Repository Admin to update.

Arguments:
  REPOSITORY    Repository name.
  value         Optional boolean ('true' or 'false'). If omitted, prints current state.
DESC;
        $mirrorExample = <<<'EX'
  # Check mirror status
  {ssh} repo mirror my-app

  # Enable mirror property
  {ssh} repo mirror my-app true
EX;
        $repo->addChild($this->leaf('mirror REPOSITORY [true|false]', 'Set or get a repository mirror property', $mirrorLong, [], [], $mirrorExample)->withUi(
            category: 'admin',
            title: 'Mirror Toggle',
            tier: 'admin',
            description: 'Set or get the mirror property',
            baseTemplate: 'repo mirror {repo}',
            fields: [FieldSpec::choiceArg('value', 'Mirror', ['true', 'false'], 'true')],
            scope: 'repo',
            order: 19,
        ));

        $privateLong = <<<'DESC'
Gets or sets whether the repository is private. Private repositories are hidden from public listings and can only be cloned or pushed by collaborators and server admins.

Scope:
  Read access to inspect; Repository Admin to update.

Arguments:
  REPOSITORY    Repository name.
  value         Optional boolean ('true' or 'false'). If omitted, prints current state.
DESC;
        $privateExample = <<<'EX'
  # Check if repository is private
  {ssh} repo private my-app

  # Make repository private
  {ssh} repo private my-app true

  # Make repository public
  {ssh} repo private my-app false
EX;
        $repo->addChild($this->leaf('private REPOSITORY [true|false]', 'Set or get a repository private property', $privateLong, [], [], $privateExample)->withUi(
            category: 'admin',
            title: 'Private Toggle',
            tier: 'admin',
            description: 'Make the repository private or public',
            baseTemplate: 'repo private {repo}',
            fields: [FieldSpec::choiceArg('value', 'Private', ['true', 'false'], 'true')],
            scope: 'repo',
            order: 17,
        ));

        $projectNameLong = <<<'DESC'
Gets the human-readable project title, or updates it if NAME is provided. Displayed in TUI and web interface headers.

Scope:
  Read access to inspect; Repository Admin to update.

Arguments:
  REPOSITORY    Repository name.
  NAME          Optional project title. If omitted, prints current title.
DESC;
        $projectNameExample = <<<'EX'
  # View current project name
  {ssh} repo project-name my-app

  # Set display title
  {ssh} repo project-name my-app "Storage Engine"
EX;
        $repo->addChild($this->leaf('project-name REPOSITORY [NAME]', 'Set or get the project name for a repository', $projectNameLong, ['project'], [], $projectNameExample)->withUi(
            category: 'admin',
            title: 'Set Project Name',
            tier: 'admin',
            description: 'Set the human-friendly project name',
            baseTemplate: 'repo project-name {repo}',
            fields: [FieldSpec::arg('name', 'Project name', 'My Project', true)],
            scope: 'repo',
            order: 15,
        ));

        $readmeLong = <<<'DESC'
Locates and prints the repository README file (e.g. README.md, README.org, README.txt) at the specified Git reference.

Scope:
  Read access to REPOSITORY required.

Arguments:
  REPOSITORY    Repository name.
  REFERENCE     Optional Git branch, tag, or commit SHA (default: HEAD).
DESC;
        $readmeExample = <<<'EX'
  # Print README from default branch (HEAD)
  {ssh} repo readme my-app

  # Print README from a specific branch
  {ssh} repo readme my-app develop

  # Print README from a release tag
  {ssh} repo readme my-app v1.0.0
EX;
        $repo->addChild($this->leaf('readme REPOSITORY [REFERENCE]', 'Print the repository README', $readmeLong, [], [], $readmeExample)->withUi(
            category: 'explore',
            title: 'View README',
            tier: 'read',
            description: 'Print the repository README in the terminal',
            baseTemplate: 'repo readme {repo}',
            fields: [FieldSpec::arg('reference', 'Reference', 'HEAD')],
            scope: 'repo',
            order: 3,
        ));

        $renameLong = <<<'DESC'
Renames a repository and moves its bare Git directory on disk. SSH and HTTP clone URLs are immediately updated to the new name.

Scope:
  Repository Owner or Server Admin.

Arguments:
  REPOSITORY    Current repository name.
  NEW_NAME      New repository name.

Notes:
  Silent on success (exit code 0).
DESC;
        $renameExample = <<<'EX'
  # Rename a repository
  {ssh} repo rename old-project new-project
EX;
        $repo->addChild($this->leaf('rename REPOSITORY NEW_NAME', 'Rename an existing repository', $renameLong, ['mv', 'move'], [], $renameExample)->withUi(
            category: 'admin',
            title: 'Rename Repository',
            tier: 'admin',
            description: 'Rename this repository',
            baseTemplate: 'repo rename {repo}',
            fields: [FieldSpec::arg('new-name', 'New name', 'new-name', true)],
            scope: 'repo',
            order: 16,
        ));

        $tagLong = <<<'DESC'
List and delete Git release and annotated tags in a repository.

Scope:
  Read access to list; Collaborator (RW) or Admin to delete.
DESC;
        $tagExample = <<<'EX'
  # List all tags in a repository
  {ssh} repo tag list my-app

  # Delete an obsolete tag
  {ssh} repo tag delete my-app v1.0.0-rc1
EX;
        $tag = $this->group('tag', 'Manage repository tags', [], $tagLong, $tagExample);

        $tagDeleteLong = <<<'DESC'
Deletes a Git tag ref from the repository.

Scope:
  Collaborator (RW) or Repository Admin.

Arguments:
  REPOSITORY    Repository name.
  TAG           Tag name to delete (e.g. "v1.0.0-rc1").
DESC;
        $tagDeleteExample = <<<'EX'
  # Delete a tag
  {ssh} repo tag delete my-app v1.0.0-rc1
EX;
        $tag->addChild($this->leaf('delete REPOSITORY TAG', 'Delete a tag', $tagDeleteLong, ['remove', 'rm', 'del'], [], $tagDeleteExample)->withUi(
            category: 'admin',
            title: 'Delete Tag',
            tier: 'admin',
            description: 'Delete a tag',
            baseTemplate: 'repo tag delete {repo}',
            fields: [FieldSpec::arg('tag', 'Tag', 'v1.0.0', true)],
            scope: 'repo',
            order: 29,
        ));

        $tagListLong = <<<'DESC'
Displays all Git tags (lightweight and annotated) existing in the repository.

Scope:
  Read access to REPOSITORY required.

Arguments:
  REPOSITORY    Repository name.
DESC;
        $tagListExample = <<<'EX'
  # List all tags
  {ssh} repo tag list my-app
EX;
        $tag->addChild($this->leaf('list REPOSITORY', 'List repository tags', $tagListLong, ['ls'], [], $tagListExample)->withUi(
            category: 'git',
            title: 'List Tags',
            tier: 'read',
            description: 'List all repository tags',
            baseTemplate: 'repo tag list {repo}',
            fields: [],
            scope: 'repo',
            order: 10,
        ));
        $repo->addChild($tag);

        $treeLong = <<<'DESC'
Lists files, directories, and symlinks at a given path and Git reference, displaying file mode, object type, SHA, and filename.

Scope:
  Read access to REPOSITORY required.

Arguments:
  REPOSITORY    Repository name.
  REFERENCE     Optional Git reference (branch, tag, or commit SHA; default: HEAD).
  PATH          Optional subdirectory path within the tree (default: root).
DESC;
        $treeExample = <<<'EX'
  # List root tree at default branch
  {ssh} repo tree my-app

  # List files in src directory on develop branch
  {ssh} repo tree my-app develop src/

  # List tree at specific commit
  {ssh} repo tree my-app 8a3f12c config/
EX;
        $repo->addChild($this->leaf('tree REPOSITORY [REFERENCE] [PATH]', 'Print repository tree at path', $treeLong, [], [], $treeExample)->withUi(
            category: 'files',
            title: 'File Tree',
            tier: 'read',
            description: 'Browse repository files and directory tree',
            baseTemplate: 'repo tree {repo}',
            fields: [
                FieldSpec::arg('reference', 'Reference', 'HEAD'),
                FieldSpec::arg('path', 'Path', 'src/'),
            ],
            scope: 'repo',
            order: 4,
        ));

        $signaturesLong = <<<'DESC'
Enforces that all commits pushed to the repository must carry valid, cryptographically verified GPG or SSH signatures. Non-signed commits are rejected by the pre-receive server hook.

Scope:
  Read access to inspect; Repository Admin to update.

Arguments:
  REPOSITORY    Repository name.
  value         Optional boolean ('true' or 'false'). If omitted, prints current requirement state.
DESC;
        $signaturesExample = <<<'EX'
  # Check signature requirement
  {ssh} repo signatures my-app

  # Require signed commits on push
  {ssh} repo signatures my-app true

  # Allow unsigned commits
  {ssh} repo signatures my-app false
EX;
        $signaturesLeaf = $this->leaf('signatures REPOSITORY [true|false]', 'Require or check verified signed commits on push', $signaturesLong, [], [], $signaturesExample);
        $signaturesLeaf->addUi(new CommandUiSpec(
            category: 'git',
            tier: 'read',
            title: 'Commit Signatures',
            description: 'Show the signed-commit enforcement status',
            baseTemplate: 'repo signatures {repo}',
            fields: [],
            scope: 'repo',
            order: 11,
        ));
        $signaturesLeaf->addUi(new CommandUiSpec(
            category: 'admin',
            tier: 'admin',
            title: 'Require Signatures',
            description: 'Require verified signed commits on push',
            baseTemplate: 'repo signatures {repo}',
            fields: [FieldSpec::choiceArg('value', 'Required', ['true', 'false'], 'true')],
            scope: 'repo',
            order: 20,
        ));
        $repo->addChild($signaturesLeaf);

        $repo->addChild($this->webhook());
        $repo->addChild($this->deploy());

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
        $webhookLong = <<<'DESC'
Configure HTTP POST webhooks dispatched automatically upon repository events (push, branch creation, tag updates, collaborator changes).

Scope:
  Repository Admin or Server Admin.
DESC;
        $webhookExample = <<<'EX'
  # List webhooks
  {ssh} repo webhook list my-app

  # Create a webhook for push events
  {ssh} repo webhook create my-app https://ci.example.com/webhook -s "secret" -e push

  # Delete a webhook by ID
  {ssh} repo webhook delete my-app 2
EX;
        $webhook = $this->group('webhook', 'Manage repository webhooks', ['webhooks'], $webhookLong, $webhookExample);

        $createLong = <<<'DESC'
Registers a new webhook endpoint. When subscribed Git events occur, codes.sh sends an HTTP POST request with a signed payload to the target URL.

Scope:
  Repository Admin or Server Admin.

Arguments:
  REPOSITORY    Repository name.
  URL           HTTP/HTTPS endpoint URL to receive webhook payloads.

Flags:
  -a, --active              Whether the webhook is active (default: true).
  -c, --content-type json   Content type of the webhook payload, can be either json or `form` (default: "json").
  -e, --events strings      Events to trigger the webhook. If omitted, all events are subscribed.
  -s, --secret string       Secret token used to sign the webhook payload via HMAC-SHA256.
DESC;
        $createExample = <<<'EX'
  # Create a webhook for all events
  {ssh} repo webhook create my-app https://ci.example.com/webhook

  # Create a secured webhook for push events
  {ssh} repo webhook create my-app https://ci.example.com/webhook -s "my-secret-key" -e push

  # Create a webhook for branch and tag creation
  {ssh} repo webhook create my-app https://deploy.example.com/hook -e branch_tag_create,push -c json
EX;
        $webhook->addChild($this->leaf('create REPOSITORY URL', 'Create a repository webhook', $createLong, [], [
            new FlagSpec('a', 'active', '', 'whether the webhook is active', 'true'),
            new FlagSpec('c', 'content-type', 'json', 'content type of the webhook payload, can be either json or `form`', '"json"'),
            new FlagSpec('e', 'events', 'strings', $events),
            new FlagSpec('s', 'secret', 'string', 'secret to sign the webhook payload'),
        ], $createExample)->withUi(
            category: 'admin',
            title: 'Create Webhook',
            tier: 'admin',
            description: 'Register an HTTP webhook endpoint',
            baseTemplate: 'repo webhook create {repo}',
            fields: [
                FieldSpec::arg('url', 'Payload URL', 'https://example.com/hook', true),
                FieldSpec::opt('events', 'Events', '-e', 'push,pull_request', 'push'),
                FieldSpec::opt('secret', 'Secret', '-s', 'shared-secret'),
                FieldSpec::choiceOpt('content-type', 'Content type', '-c', ['json', 'form'], 'json'),
                FieldSpec::choiceOpt('active', 'Active', '-a', ['true', 'false'], 'true'),
            ],
            scope: 'repo',
            order: 31,
        ));

        $deleteLong = <<<'DESC'
Permanently deletes a repository webhook by its numeric identifier.

Scope:
  Repository Admin or Server Admin.

Arguments:
  REPOSITORY    Repository name.
  WEBHOOK_ID    Numeric identifier of the webhook (see 'repo webhook list').
DESC;
        $deleteExample = <<<'EX'
  # Delete webhook #2
  {ssh} repo webhook delete my-app 2
EX;
        $webhook->addChild($this->leaf('delete REPOSITORY WEBHOOK_ID', 'Delete a repository webhook', $deleteLong, [], [], $deleteExample)->withUi(
            category: 'admin',
            title: 'Delete Webhook',
            tier: 'admin',
            description: 'Remove a webhook by id',
            baseTemplate: 'repo webhook delete {repo}',
            fields: [FieldSpec::arg('webhook-id', 'Webhook id', '42', true)],
            scope: 'repo',
            order: 32,
        ));

        $listLong = <<<'DESC'
Displays all configured webhooks for a repository, including webhook ID, target URL, subscribed events, payload format, and active status.

Scope:
  Repository Admin or Server Admin.

Arguments:
  REPOSITORY    Repository name.
DESC;
        $listExample = <<<'EX'
  # List all webhooks for a repository
  {ssh} repo webhook list my-app
EX;
        $webhook->addChild($this->leaf('list REPOSITORY', 'List repository webhooks', $listLong, [], [], $listExample)->withUi(
            category: 'admin',
            title: 'List Webhooks',
            tier: 'admin',
            description: 'List configured HTTP webhook endpoints',
            baseTemplate: 'repo webhook list {repo}',
            fields: [],
            scope: 'repo',
            order: 30,
        ));

        return $webhook;
    }

    private function deploy(): CommandSpec
    {
        $deployLong = <<<'DESC'
Manage multi-environment Docker container deployments, resource quotas, encrypted environment variables, and Caddy custom domains for a repository.

Scope:
  Write/Admin access on REPOSITORY and authorized by the server deployment policy.
DESC;
        $deployExample = <<<'EX'
  # Enable automatic deployment on the main branch
  {ssh} repo deploy enable my-app main

  # Set encrypted environment secrets for main
  {ssh} repo deploy env set my-app main APP_ENV=prod

  # Attach a custom domain to an explicit service and internal container port
  {ssh} repo deploy domain add my-app main app.example.com --service=web --port=80

  # Inspect deployment status and logs
  {ssh} repo deploy status my-app
  {ssh} repo deploy logs my-app main --follow
EX;
        $deploy = $this->group('deploy', 'Manage repository Docker deployments', ['deployments', 'deployment'], $deployLong, $deployExample);

        $deploy->addChild($this->leaf(
            'enable REPOSITORY [REF]',
            'Enable automatic Docker deployment for a branch or tag',
            'Enables automatic container deployment for a repository branch or tag (defaults to the repository default branch).',
        )->withUi(
            category: 'admin',
            title: 'Enable Deployment',
            tier: 'write',
            description: 'Enable automatic Docker deployment for a branch or tag',
            baseTemplate: 'repo deploy enable {repo}',
            fields: [FieldSpec::arg('ref', 'Branch or tag', 'main')],
            scope: 'repo',
            order: 40,
        ));

        $deploy->addChild($this->leaf(
            'disable REPOSITORY REF',
            'Disable deployment and stop containers for a branch or tag',
            'Disables deployment and stops running containers for the specified branch or tag.',
        )->withUi(
            category: 'admin',
            title: 'Disable Deployment',
            tier: 'write',
            description: 'Disable deployment and stop containers for a branch or tag',
            baseTemplate: 'repo deploy disable {repo}',
            fields: [FieldSpec::arg('ref', 'Branch or tag', 'main', true)],
            scope: 'repo',
            order: 41,
        ));

        $deploy->addChild($this->leaf(
            'status REPOSITORY [REF]',
            'Show deployment environments status for a repository',
            'Displays deployed branches/tags, container status, active commit SHA, attached domains, and resource quotas.',
            ['list', 'ls'],
        )->withUi(
            category: 'git',
            title: 'Deployment Status',
            tier: 'write',
            description: 'Show deployment environments status, domains, and quotas',
            baseTemplate: 'repo deploy status {repo}',
            fields: [FieldSpec::arg('ref', 'Branch or tag', 'main')],
            scope: 'repo',
            order: 42,
        ));

        $deploy->addChild($this->leaf(
            'trigger REPOSITORY REF',
            'Trigger a deployment build for an enabled branch or tag',
            'Queues an immediate build and deployment for the current commit of the specified branch or tag.',
        )->withUi(
            category: 'admin',
            title: 'Trigger Deployment',
            tier: 'write',
            description: 'Trigger a deployment build for an enabled branch or tag',
            baseTemplate: 'repo deploy trigger {repo}',
            fields: [FieldSpec::arg('ref', 'Branch or tag', 'main', true)],
            scope: 'repo',
            order: 43,
        ));

        $redeployLong = <<<'DESC'
Triggers an immediate re-build and redeployment for one repository (all active branches or a specific ref), or all active repositories on the platform with --all.

Arguments:
  REPOSITORY    Repository name (optional if --all is specified).
  REF           Branch or tag name (optional).
DESC;

        $deploy->addChild($this->leaf(
            'redeploy [REPOSITORY] [REF]',
            'Rebuild and restart containers for active deployments',
            $redeployLong,
            [],
            [
                new FlagSpec(null, 'all', '', 'Redeploy all active deployment environments across the server (Admin only)'),
            ],
            <<<'EX'
  # Redeploy all active branches of a repository
  {ssh} repo deploy redeploy my-app

  # Redeploy a specific branch
  {ssh} repo deploy redeploy my-app main

  # Redeploy all active deployments across the server (Admin)
  {ssh} repo deploy redeploy --all
EX
        )->withUi(
            category: 'admin',
            title: 'Redeploy Active Containers',
            tier: 'write',
            description: 'Rebuild and restart containers for active deployments',
            baseTemplate: 'repo deploy redeploy {repo}',
            fields: [FieldSpec::arg('ref', 'Branch or tag (optional)', '', false)],
            scope: 'repo',
            order: 44,
        ));

        $deploy->addChild($this->leaf(
            'stop REPOSITORY REF',
            'Stop running containers for a deployed branch or tag',
        )->withUi(
            category: 'admin',
            title: 'Stop Deployment Containers',
            tier: 'write',
            description: 'Stop running containers for a deployed branch or tag',
            baseTemplate: 'repo deploy stop {repo}',
            fields: [FieldSpec::arg('ref', 'Branch or tag', 'main', true)],
            scope: 'repo',
            order: 44,
        ));

        $deploy->addChild($this->leaf(
            'restart REPOSITORY REF',
            'Restart containers for a deployed branch or tag',
        )->withUi(
            category: 'admin',
            title: 'Restart Deployment Containers',
            tier: 'write',
            description: 'Restart containers for a deployed branch or tag',
            baseTemplate: 'repo deploy restart {repo}',
            fields: [FieldSpec::arg('ref', 'Branch or tag', 'main', true)],
            scope: 'repo',
            order: 45,
        ));

        $deploy->addChild($this->leaf(
            'logs REPOSITORY REF',
            'Print build and container logs for a deployed branch or tag',
            'Prints release build logs and service container logs.',
            [],
            [
                new FlagSpec('s', 'service', 'string', 'filter container logs by service name'),
                new FlagSpec('b', 'build', '', 'show only build logs of the latest release'),
                new FlagSpec('f', 'follow', '', 'follow build logs until user stops (Ctrl+C)'),
            ],
        )->withUi(
            category: 'git',
            title: 'Deployment Logs',
            tier: 'write',
            description: 'Print build and container logs for a deployed branch or tag',
            baseTemplate: 'repo deploy logs {repo}',
            fields: [
                FieldSpec::arg('ref', 'Branch or tag', 'main', true),
                FieldSpec::opt('service', 'Service name', '--service', 'web'),
                FieldSpec::boolOpt('build', 'Build logs only', '--build', false),
                FieldSpec::boolOpt('follow', 'Follow live', '--follow', false),
            ],
            scope: 'repo',
            order: 46,
        ));

        $deploy->addChild($this->leaf(
            'allow REPOSITORY [true|false]',
            'Allow or disallow deployments on a repository or ref pattern (Admin)',
            'Server admin command to grant or revoke deployment rights on a repository or a specific branch/tag/glob pattern.',
            [],
            [new FlagSpec('r', 'ref', 'string', 'branch, tag, or glob pattern (e.g. main, release/*, v*)')],
        ));

        $deploy->addChild($this->leaf(
            'quota REPOSITORY',
            'Configure or view resource quotas for a repository or ref pattern (Admin)',
            'Server admin command to set or inspect CPU, RAM, PIDs, max-envs, and max-services quotas per repository or branch/tag pattern.',
            [],
            [
                new FlagSpec('r', 'ref', 'string', 'branch, tag, or glob pattern'),
                new FlagSpec(null, 'cpus', 'float', 'CPU limit per container (e.g. 1.0, 2.0)'),
                new FlagSpec(null, 'memory', 'string', 'Memory limit per container (e.g. 512m, 2g)'),
                new FlagSpec(null, 'pids', 'int', 'Max processes (PIDs) per container (e.g. 128)'),
                new FlagSpec(null, 'max-envs', 'int', 'Max concurrent deployed environments'),
                new FlagSpec(null, 'max-services', 'int', 'Max services allowed in compose.yaml'),
            ],
        ));

        $deploy->addChild($this->leaf(
            'exec REPOSITORY REF SERVICE COMMAND...',
            'Execute a one-shot command inside a running service container (Admin)',
        ));

        $envGroup = $this->group('env', 'Manage encrypted environment variables per deployed branch or tag');
        $envGroup->addChild($this->leaf('set REPOSITORY REF KEY=VALUE...', 'Set encrypted environment variables')->withUi(
            category: 'admin',
            title: 'Set Deploy Env Var',
            tier: 'write',
            description: 'Set encrypted environment variable for a deployed branch or tag',
            baseTemplate: 'repo deploy env set {repo}',
            fields: [
                FieldSpec::arg('ref', 'Branch or tag', 'main', true),
                FieldSpec::arg('pair', 'KEY=VALUE', 'APP_ENV=prod', true),
            ],
            scope: 'repo',
            order: 47,
        ));
        $envGroup->addChild($this->leaf('get REPOSITORY REF KEY', 'Get an environment variable', '', [], [
            new FlagSpec(null, 'reveal', '', 'reveal the decrypted secret value'),
        ]));
        $envGroup->addChild($this->leaf('unset REPOSITORY REF KEY', 'Remove an environment variable', '', ['rm', 'delete']));
        $envGroup->addChild($this->leaf('list REPOSITORY REF', 'List environment variable keys', '', ['ls']));
        $deploy->addChild($envGroup);

        $domainGroup = $this->group('domain', 'Manage custom domains and Caddy service exposure per deployed branch or tag');
        $domainGroup->addChild($this->leaf(
            'add REPOSITORY REF DOMAIN',
            'Attach a custom domain to an explicit service and internal port',
            'Validates FQDN syntax, checks for collisions and public DNS resolution, attaches the service to Caddy, and runs an HTTP smoke test.',
            [],
            [
                new FlagSpec('s', 'service', 'string', 'target service name in compose.yaml (required)'),
                new FlagSpec('p', 'port', 'int', 'target internal container port (required)'),
                new FlagSpec(null, 'skip-dns-check', '', 'skip public DNS resolution check'),
            ],
        )->withUi(
            category: 'admin',
            title: 'Add Deploy Domain',
            tier: 'write',
            description: 'Attach a custom domain to a service and internal container port',
            baseTemplate: 'repo deploy domain add {repo}',
            fields: [
                FieldSpec::arg('ref', 'Branch or tag', 'main', true),
                FieldSpec::arg('domain', 'Domain (FQDN)', 'app.example.com', true),
                FieldSpec::opt('service', 'Service name', '--service', 'web', 'web'),
                FieldSpec::opt('port', 'Container port', '--port', '80', '80'),
            ],
            scope: 'repo',
            order: 48,
        ));
        $domainGroup->addChild($this->leaf('get REPOSITORY REF DOMAIN', 'Get details of an attached domain'));
        $domainGroup->addChild($this->leaf('remove REPOSITORY REF DOMAIN', 'Remove an attached domain', '', ['rm', 'delete']));
        $domainGroup->addChild($this->leaf('list REPOSITORY [REF]', 'List attached domains', '', ['ls']));
        $deploy->addChild($domainGroup);

        return $deploy;
    }
}
