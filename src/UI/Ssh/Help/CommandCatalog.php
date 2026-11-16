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
        $root = new CommandSpec('', '', '', $rootLong, [], [], false);
        $root->addChild($this->signupSpec());

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
        ], $aboutExample));

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
        $root->addChild($this->leaf('help [command]', 'Help about any command', $helpLong, [], [], $helpExample));

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
        $root->addChild($this->leaf('info', 'Show your info', $infoLong, [], [], $infoExample));

        $jwtLong = <<<'DESC'
Generates an Ed25519 JSON Web Token (JWT) valid for 1 hour to authenticate Git Large File Storage (LFS) operations over HTTP/HTTPS.

Scope:
  Authenticated user with read access to the specified repositories.

Arguments:
  repository    One or more repository names the token will grant LFS access for.

Output:
  Prints the encoded JWT bearer string directly to stdout for use with HTTP Authorization headers or git-lfs credential helpers.
DESC;
        $jwtExample = <<<'EX'
  # Generate an LFS token for a single repository
  {ssh} jwt my-repo

  # Generate an LFS token valid for multiple repositories
  {ssh} jwt repo1 repo2 repo3
EX;
        $root->addChild($this->leaf('jwt [repository1 repository2...]', 'Generate a JSON Web Token', $jwtLong, [], [], $jwtExample));

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
        $root->addChild($this->leaf('set-username USERNAME', 'Set your username', $setUsernameLong, [], [], $setUsernameExample));

        $root->addChild($this->signupSpec());
        if ($includeAdmin) {
            $root->addChild($this->settings());
        }
        $root->addChild($this->token());
        if ($includeAdmin) {
            $root->addChild($this->user());
        }

        return $root;
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
        $web->addChild($this->leaf('login', 'Log your browser in and open the web interface'));
        $web->addChild($this->leaf('logout', 'Sign out of every active browser session'));

        return $web;
    }

    private function signupSpec(): CommandSpec
    {
        $signupLong = <<<'DESC'
Registers your SSH key as a new user account on codes.sh. Once registered, you can immediately create, clone, and push Git repositories.

Scope:
  Unregistered SSH keys only. Requires server setting 'allow-signup' to be enabled.

Arguments:
  USERNAME    Desired unique username for your account.

Flags:
  -k, --key string    Register a specific public key instead of the key you connected with.
DESC;
        $signupExample = <<<'EX'
  # Register yourself using the SSH key from your current connection
  {ssh} signup alice

  # Register yourself with an explicit public key
  {ssh} signup alice -k "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG... alice@laptop"
EX;

        return $this->leaf('signup USERNAME', 'Register yourself as a new user with your SSH key', $signupLong, [], [
            new FlagSpec('k', 'key', 'string', 'public key to register instead of the one you connected with'),
        ], $signupExample);
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
        $pubkey->addChild($this->leaf('add AUTHORIZED_KEY', 'Add a public key', $addLong, [], [], $addExample));

        $listLong = <<<'DESC'
Displays all OpenSSH public keys registered under your user account, including key algorithm, base64 payload, and optional comment.

Scope:
  Authenticated user.
DESC;
        $listExample = <<<'EX'
  # List all your authorized keys
  {ssh} pubkey list
EX;
        $pubkey->addChild($this->leaf('list', 'List public keys', $listLong, ['ls'], [], $listExample));

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
        $pubkey->addChild($this->leaf('remove AUTHORIZED_KEY', 'Remove a public key', $removeLong, [], [], $removeExample));

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
        $settings->addChild($this->leaf('allow-keyless [true|false]', 'Set or get allow keyless access to repositories', $allowKeylessLong, [], [], $allowKeylessExample));

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
        $settings->addChild($this->leaf('allow-signup [true|false]', 'Set or get whether unregistered keys may self-register', $allowSignupLong, [], [], $allowSignupExample));

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
        $settings->addChild($this->leaf('anon-access [ACCESS_LEVEL]', 'Set or get the default access level for anonymous users', $anonAccessLong, [], [], $anonAccessExample));

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
        ], $createExample));

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
        $token->addChild($this->leaf('delete ID', 'Delete an access token', $deleteLong, ['rm', 'remove'], [], $deleteExample));

        $listLong = <<<'DESC'
Displays all active personal access tokens for your account, listing numeric token ID, label name, creation timestamp, and expiration date.

Scope:
  Authenticated user.
DESC;
        $listExample = <<<'EX'
  # List all your access tokens
  {ssh} token list
EX;
        $token->addChild($this->leaf('list', 'List access tokens', $listLong, ['ls'], [], $listExample));

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
        $user->addChild($this->leaf('add-pubkey USERNAME AUTHORIZED_KEY', 'Add a public key to a user', $addPubkeyLong, [], [], $addPubkeyExample));

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
        ], $createExample));

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
        $user->addChild($this->leaf('delete USERNAME', 'Delete a user', $deleteLong, [], [], $deleteExample));

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
        $user->addChild($this->leaf('info USERNAME', 'Show information about a user', $infoLong, [], [], $infoExample));

        $listLong = <<<'DESC'
Displays all registered user accounts on the server, showing administrator status and count of registered public keys.

Scope:
  Server Admin.
DESC;
        $listExample = <<<'EX'
  # List all server users
  {ssh} user list
EX;
        $user->addChild($this->leaf('list', 'List users', $listLong, ['ls'], [], $listExample));

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
        $user->addChild($this->leaf('remove-pubkey USERNAME AUTHORIZED_KEY', 'Remove a public key from a user', $removePubkeyLong, [], [], $removePubkeyExample));

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
        $user->addChild($this->leaf('set-admin USERNAME [true|false]', 'Make a user an admin', $setAdminLong, [], [], $setAdminExample));

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
        $user->addChild($this->leaf('set-username USERNAME NEW_USERNAME', "Change a user's username", $setUsernameLong, [], [], $setUsernameExample));

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
        ], $blobExample));

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
        $branch->addChild($this->leaf('default REPOSITORY [BRANCH]', 'Set or get the default branch', $branchDefaultLong, [], [], $branchDefaultExample));

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
        $branch->addChild($this->leaf('delete REPOSITORY BRANCH', 'Delete a branch', $branchDeleteLong, ['remove', 'rm', 'del'], [], $branchDeleteExample));

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
        $branch->addChild($this->leaf('list REPOSITORY', 'List repository branches', $branchListLong, [], [], $branchListExample));

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
        ], $branchProtectExample));

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
        $branch->addChild($this->leaf('unprotect REPOSITORY PATTERN', 'Remove a branch protection rule', $branchUnprotectLong, [], [], $branchUnprotectExample));
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
        $collab->addChild($this->leaf('list REPOSITORY', 'List collaborators for a repo', $collabListLong, [], [], $collabListExample));

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
        $collab->addChild($this->leaf('remove REPOSITORY USERNAME', 'Remove a collaborator from a repo', $collabRemoveLong, [], [], $collabRemoveExample));
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
        ], $commitExample));

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
        $repo->addChild($this->leaf('create REPOSITORY', 'Create a new repository', $createLong, [], $this->repoWriteFlags(), $createExample));

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
        $repo->addChild($this->leaf('delete REPOSITORY', 'Delete a repository', $deleteLong, ['del', 'remove', 'rm'], [], $deleteExample));

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
        $repo->addChild($this->leaf('description REPOSITORY [DESCRIPTION]', 'Set or get the description for a repository', $descriptionLong, ['desc'], [], $descriptionExample));

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
        $repo->addChild($this->leaf('diff REPOSITORY FROM [TO]', 'Print the diff between two references', $diffLong, [], [], $diffExample));

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
        $repo->addChild($this->leaf('fork SOURCE [NEW_NAME]', 'Fork an existing repository', $forkLong, [], $this->repoWriteFlags(), $forkExample));

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
        $repo->addChild($this->leaf('grep REPOSITORY PATTERN [REFERENCE]', 'Search repository contents', $grepLong, ['search'], [], $grepExample));

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
        $repo->addChild($this->leaf('hidden REPOSITORY [TRUE|FALSE]', 'Hide or unhide a repository', $hiddenLong, ['hide'], [], $hiddenExample));

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
        ], $importExample));

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
        $repo->addChild($this->leaf('info REPOSITORY', 'Get information about a repository', $infoRepoLong, [], [], $infoRepoExample));

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
        ], $listRepoExample));

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
        $repo->addChild($this->leaf('mirror REPOSITORY [true|false]', 'Set or get a repository mirror property', $mirrorLong, [], [], $mirrorExample));

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
        $repo->addChild($this->leaf('private REPOSITORY [true|false]', 'Set or get a repository private property', $privateLong, [], [], $privateExample));

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
        $repo->addChild($this->leaf('project-name REPOSITORY [NAME]', 'Set or get the project name for a repository', $projectNameLong, ['project'], [], $projectNameExample));

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
        $repo->addChild($this->leaf('readme REPOSITORY [REFERENCE]', 'Print the repository README', $readmeLong, [], [], $readmeExample));

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
        $repo->addChild($this->leaf('rename REPOSITORY NEW_NAME', 'Rename an existing repository', $renameLong, ['mv', 'move'], [], $renameExample));

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
        $tag->addChild($this->leaf('delete REPOSITORY TAG', 'Delete a tag', $tagDeleteLong, ['remove', 'rm', 'del'], [], $tagDeleteExample));

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
        $tag->addChild($this->leaf('list REPOSITORY', 'List repository tags', $tagListLong, ['ls'], [], $tagListExample));
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
        $repo->addChild($this->leaf('tree REPOSITORY [REFERENCE] [PATH]', 'Print repository tree at path', $treeLong, [], [], $treeExample));

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
        $repo->addChild($this->leaf('signatures REPOSITORY [true|false]', 'Require or check verified signed commits on push', $signaturesLong, [], [], $signaturesExample));

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
        ], $createExample));

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
        $webhook->addChild($this->leaf('delete REPOSITORY WEBHOOK_ID', 'Delete a repository webhook', $deleteLong, [], [], $deleteExample));

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
        $webhook->addChild($this->leaf('list REPOSITORY', 'List repository webhooks', $listLong, [], [], $listExample));

        return $webhook;
    }
}
