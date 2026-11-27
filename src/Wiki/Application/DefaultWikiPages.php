<?php

declare(strict_types=1);

namespace App\Wiki\Application;

final class DefaultWikiPages
{
    /**
     * @return list<array{slug: string, title: string, category: string, position: int, content: string}>
     */
    public static function all(): array
    {
        return [
            [
                'slug' => 'home',
                'title' => 'gickers.sh Server Documentation & Knowledge Base',
                'category' => 'Overview',
                'position' => 0,
                'content' => self::homeMarkdown(),
            ],
            [
                'slug' => 'signup',
                'title' => 'Self-Service SSH Signup & Account Registration',
                'category' => 'Signup',
                'position' => 10,
                'content' => self::signupMarkdown(),
            ],
            [
                'slug' => 'ssh-keys',
                'title' => 'SSH Keys & Permanent Agent Storage (ssh-add & Keychain)',
                'category' => 'Login',
                'position' => 20,
                'content' => self::sshKeysMarkdown(),
            ],
            [
                'slug' => 'web-login',
                'title' => 'Passwordless Web Login & Personal Access Tokens',
                'category' => 'Login',
                'position' => 21,
                'content' => self::webLoginMarkdown(),
            ],
            [
                'slug' => 'repo-management',
                'title' => 'Git Repositories: Creation, Clone & Workflow',
                'category' => 'Repo',
                'position' => 30,
                'content' => self::repoManagementMarkdown(),
            ],
            [
                'slug' => 'deploy-guide',
                'title' => 'Container Deployment Engine & Custom Domains',
                'category' => 'Deploy',
                'position' => 40,
                'content' => self::deployGuideMarkdown(),
            ],
            [
                'slug' => 'collaboration',
                'title' => 'Collaboration, Permissions & Key Governance',
                'category' => 'Collab',
                'position' => 50,
                'content' => self::collaborationMarkdown(),
            ],
        ];
    }

    public static function homeMarkdown(): string
    {
        return <<<'MD'
# gickers.sh Server Documentation & Knowledge Base

Welcome to the **gickers.sh** knowledge base. **gickers.sh** is a lightweight, zero-password Git hosting forge and container deployment platform designed from the ground up for terminal-first developers.

All interactions — authentication, account provisioning, repository management, deployment automation, and documentation reading — are fully accessible over **SSH CLI**, interactive **TUI (Terminal User Interface)**, and a modern **Web interface**.

---

## Documentation Categories

Browse through the core sections of the platform:

| Category | Topics Covered | Key Guides |
| :--- | :--- | :--- |
| **🚀 Signup** | Self-service registration, cryptographic identity, SSH key generation, handle rules. | [SSH Signup & Registration](/wiki/signup) |
| **🔑 Login** | Persistent agent storage, passwordless web magic links, Personal Access Tokens (PAT). | [SSH Keys & `ssh-add`](/wiki/ssh-keys)<br>[Passwordless Web Login](/wiki/web-login) |
| **📦 Repo** | Creating repositories, SSH & Smart HTTP cloning, on-demand auto-creation, branching. | [Repositories & Workflow](/wiki/repo-management) |
| **🚢 Deploy** | Docker Compose deployment engine, automatic Caddy HTTPS, custom domains, encrypted env vars. | [Deployments & Domains](/wiki/deploy-guide) |
| **👥 Collab** | Collaborator permissions (read/write/admin), multi-key workflows, server governance. | [Collaboration & Keys](/wiki/collaboration) |

---

## Quick Navigation: Core Guides

### 1. [🚀 Signup: Claim Your Username in 5 Seconds](/wiki/signup)
Unified signup and automated client onboarding in one command:
```bash
ssh gickers.sh signup | bash      # Linux / macOS
ssh gickers.sh signup | iex       # Windows (PowerShell)
```

### 2. [🔑 Login: Set Up `ssh-add` & Never Type Passphrases Again](/wiki/ssh-keys)
Configure your operating system (macOS Keychain, Linux systemd/keychain, Windows OpenSSH service) to store key passphrases permanently across reboots while keeping your private key safely encrypted.

### 3. [🔑 Login: Access the Web UI via Passwordless Magic Links](/wiki/web-login)
Log in to the web interface in one keystroke without managing passwords:
```bash
ssh gickers.sh web login
```

### 4. [📦 Repo: Git Repository Lifecycle & Push-to-Create](/wiki/repo-management)
Create repositories explicitly via CLI or push any local repository to create it on demand:
```bash
# Push directly to create on the fly:
git push ssh://gickers.sh/my-app.git main
```

### 5. [🚢 Deploy: Automated Container Deployments](/wiki/deploy-guide)
Deploy your applications directly from Git branches with automatic Caddy TLS reverse-proxy routing:
```bash
# Enable deployments on the main branch:
ssh gickers.sh repo deploy enable my-app main

# Add a custom domain with automatic HTTPS:
ssh gickers.sh repo deploy domain add my-app main my-app.example.com --service web --port 80
```

### 6. [👥 Collab: Team Access Control & Multi-Device Keys](/wiki/collaboration)
Invite team members with fine-grained access control (`read`, `write`, `admin`) and register backup keys:
```bash
ssh gickers.sh repo collab add my-app bob write
ssh gickers.sh pubkey add "ssh-ed25519 AAAAC3... alice@laptop"
```

---

## Essential CLI Cheat-Sheet

```bash
# View your account identity, role, and registered keys
ssh gickers.sh info

# Launch the interactive Terminal User Interface (TUI)
ssh gickers.sh

# List all repositories you own or collaborate on
ssh gickers.sh repo list

# Browse and search documentation directly in your terminal
ssh gickers.sh wiki list
ssh gickers.sh wiki show deploy-guide
```
MD;
    }

    public static function signupMarkdown(): string
    {
        return <<<'MD'
# Self-Service SSH Signup & Account Registration

**gickers.sh** uses a **zero-password, terminal-first** identity model. Instead of filling out web registration forms or managing passwords, your **OpenSSH public key** is your cryptographic identity.

When self-service registration (`allow-signup`) is enabled on the server, anyone with an SSH key pair can claim a username in a single command and immediately start creating, cloning, and pushing Git repositories.

> **Tip:** To avoid re-entering your SSH key passphrase each time you connect or push to **gickers.sh**, configure `ssh-add` and permanent local keychain storage. See the [SSH Keys & Permanent Storage Guide](/wiki/ssh-keys).

---

## 1. Prerequisites: Generate an SSH Key Pair

If you do not already have an SSH key pair (`~/.ssh/id_ed25519`), generate one using `ssh-keygen`:

```bash
ssh-keygen -t ed25519 -C "you@example.com"
```

Verify that your public key exists:

```bash
cat ~/.ssh/id_ed25519.pub
# ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG... you@example.com
```

---

## 2. Registering Your Account (`signup`)

### Unified Signup & Client Onboarding (Recommended)

Everything is unified into a single command that runs the interactive TokyoNight onboarding wizard to register your account and configure `~/.ssh/config`:

```bash
# macOS / Linux
ssh gickers.sh signup | bash

# Windows (PowerShell)
ssh gickers.sh signup | iex
```

### Direct CLI Registration

Connect to the server over SSH and run `signup <username>`. The server automatically extracts the public key presented during the SSH handshake and binds it to your new account:

```bash
ssh gickers.sh signup alice
```

> **Note:** If the server listens on a custom SSH port (for example `2223` in local development), add `-p <port>`:
> ```bash
> ssh -p 2223 localhost signup alice
> ```

### Shortcut: Target Username in the SSH Host (`<username>@host`)

You can also specify your desired handle directly as the SSH login user (`alice@gickers.sh`). When you invoke `signup` without positional arguments, **gickers.sh** automatically claims the username from the SSH connection target:

```bash
ssh alice@gickers.sh signup
```

If you connect as `ssh alice@gickers.sh` before the account exists, the server will guide you with the exact command to claim `@alice`:

```text
Account @alice does not exist. Claim this username with: ssh alice@gickers.sh signup
```

### Registering an Explicit Public Key (`-k` / `--key`)

If you want to register a different public key than the one currently used by your SSH agent (for example, bootstrapping a key for another workstation or a CI deploy key), pass the public key line with `-k` / `--key`:

```bash
ssh gickers.sh signup alice -k "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG... alice@laptop"
```

---

## 3. Username Rules & Normalization

All usernames are normalized and validated by the domain model (`User::normalizeUsername`):

| Rule | Specification | Example Valid |
| :--- | :--- | :--- |
| **Case Normalization** | Stored in **lowercase** (`Alice` becomes `alice`) | `alice` |
| **First Character** | Must start with a **letter** (`a-z`) | `dev-lead` |
| **Allowed Characters** | Letters, numbers, and hyphens (`a-z`, `0-9`, `-`) | `backend-01` |
| **Maximum Length** | Up to **64 characters** | `alice-dev-team` |
| **Uniqueness** | Each username is globally unique across the server | `bob` |

---

## 4. What to Do Right After Signing Up

Once `signup` prints `Welcome, alice! Your SSH key is registered`, you can immediately explore:

```bash
# 1. Verify your identity and permissions
ssh gickers.sh info

# 2. Launch the full-screen interactive TUI
ssh gickers.sh

# 3. Log in to the Web interface
ssh gickers.sh web login

# 4. Create your first Git repository
ssh gickers.sh repo create my-project
```

---

## 5. Security Model & Server Administration

### Restricted Sandbox for Unregistered Keys

When a client connects with an **unregistered SSH public key**:
- The session is strictly confined to the `signup` command and `help` (`help`, `help signup`, `signup --help`).
- Starting the TUI, listing repositories, running Git pack transports, or probing other commands is blocked until the key is registered.
- Even if `allow-keyless` is set to `false`, an unknown SSH key is still permitted to reach `signup` as long as `allow-signup` is `true`.

### Enabling or Disabling Self-Service Signup (Server Admin)

Self-service registration is **enabled by default** (`true`). Server administrators can inspect or toggle the policy live over SSH:

```bash
# Check current self-service signup policy:
ssh gickers.sh settings allow-signup

# Disable self-service signup (closed / private server):
ssh gickers.sh settings allow-signup false

# Re-enable self-service signup:
ssh gickers.sh settings allow-signup true
```
MD;
    }

    public static function sshKeysMarkdown(): string
    {
        return <<<'MD'
# SSH Key Management & Permanent Local Storage (ssh-add & Keychain)

**gickers.sh** authenticates all Git pushes, SSH CLI commands, and TUI sessions using your **OpenSSH key pair**. Protecting your private key with a **strong passphrase** is an essential security best practice.

However, having to type your passphrase on every `git push`, `ssh gickers.sh`, or automated workflow creates friction. The OpenSSH Authentication Agent (`ssh-agent`) and `ssh-add` solve this problem by storing your decrypted private keys safely **in memory**.

Furthermore, you can configure your operating system (**macOS Keychain**, **Linux systemd / GNOME Keyring / keychain**, and **Windows OpenSSH Agent**) to retain your keys **permanently across reboots**, eliminating passphrase prompts entirely while keeping your private keys strictly encrypted on disk.

---

## 1. What Is `ssh-agent` and How Does `ssh-add` Work?

`ssh-agent` is a background daemon that runs on your local machine. When an application (like `git` or `ssh`) needs to authenticate, it asks `ssh-agent` to sign a challenge with your private key. The private key **never leaves the agent** and is never exposed to the server or network.

`ssh-add` is the CLI tool used to manage keys stored in `ssh-agent`.

### Essential `ssh-add` Commands

| Command | Action & Purpose |
| :--- | :--- |
| `ssh-add ~/.ssh/id_ed25519` | Add your default private key to the agent for the current session. |
| `ssh-add -l` | List the **fingerprints** (SHA256) of all keys currently held in memory. |
| `ssh-add -L` | Print the full **public keys** of all identities currently loaded in the agent. |
| `ssh-add -d ~/.ssh/id_ed25519` | Remove/unload a specific private key from the agent. |
| `ssh-add -D` | Remove **all** keys from the agent (flush agent memory). |
| `ssh-add -t 8h ~/.ssh/id_ed25519` | Load a key with a temporary **lifetime** (e.g. automatically expires after 8 hours). |
| `ssh-add -c ~/.ssh/id_ed25519` | Require interactive **confirmation** prompt each time the key is used to sign. |

---

## 2. macOS: Permanent Storage via Apple Keychain

Modern macOS includes built-in integration between Apple's OpenSSH client and the **macOS Keychain**. When enabled, macOS stores your private key's passphrase in the secure hardware-backed Keychain and automatically adds the key to `ssh-agent` on login.

### Step 1: Configure `~/.ssh/config`

```ssh-config
Host *
  AddKeysToAgent yes
  UseKeychain yes
  IdentityFile ~/.ssh/id_ed25519
```

### Step 2: Store Your Passphrase in the Keychain (One-Time Command)

```bash
# macOS Monterey, Ventura, Sonoma, Sequoia (and later):
ssh-add --apple-use-keychain ~/.ssh/id_ed25519

# Legacy macOS (Sierra to Big Sur):
ssh-add -K ~/.ssh/id_ed25519
```

---

## 3. Linux: Permanent Storage (Desktop & Terminal)

### Option A: Desktop Environments (GNOME, KDE Plasma)

Configure `~/.ssh/config` with `AddKeysToAgent yes`:

```ssh-config
Host *
  AddKeysToAgent yes
  IdentityFile ~/.ssh/id_ed25519
```

GNOME Keyring or KDE Wallet will prompt you once to unlock the key on login.

### Option B: Terminal, Headless Servers & Window Managers with `keychain`

```bash
# Install keychain (Ubuntu/Debian: apt install keychain, Fedora: dnf install keychain)
# Add to ~/.bashrc or ~/.zshrc:
eval $(keychain --eval --agents ssh id_ed25519)
```

---

## 4. Windows: OpenSSH Authentication Agent & WSL

In **PowerShell as Administrator**:

```powershell
# Set service to start automatically on system boot
Get-Service -Name ssh-agent | Set-Service -StartupType Automatic
Start-Service ssh-agent

# Add your private key once
ssh-add ~\.ssh\id_ed25519
```

---

## 5. Recommended `~/.ssh/config` for gickers.sh

```ssh-config
Host gickers.sh
  HostName gickers.sh
  Port 22
  User alice
  IdentityFile ~/.ssh/id_ed25519
  IdentitiesOnly yes
  AddKeysToAgent yes
  # Enable on macOS:
  # UseKeychain yes
```
MD;
    }

    public static function webLoginMarkdown(): string
    {
        return <<<'MD'
# Passwordless Web Login & Personal Access Tokens

**gickers.sh** provides a passwordless web experience. You never have to create, remember, or reset a password.

---

## 1. Instant Web Login via SSH (`web login`)

To access the Web UI:

```bash
ssh gickers.sh web login
```

The server generates a strictly single-use authentication URL:

```text
Open this link in your browser to sign in (valid for 10 minutes):
  https://gickers.sh/login/wlt_8c4f92...
```
The token is invalidated immediately upon first redemption.

### Terminal Hyperlink & Clipboard Shortcuts

- **OSC 8 Hyperlinks**: Supported terminal emulators (iTerm2, Alacritty, Kitty, WezTerm, VS Code terminal) will render the URL as a clickable link.
- **OSC 52 Clipboard**: The authentication link is automatically copied to your local system clipboard upon emission.
- **Auto-Open**: On macOS and Linux desktop sessions, pressing <kbd>Enter</kbd> launches your default browser immediately.

---

## 2. Personal Access Tokens (PAT) for HTTP & CI/CD

When interacting with Git repositories over **HTTPS**, Git LFS, or inside CI/CD pipelines (such as GitHub Actions, GitLab CI, or Docker builds), use **Personal Access Tokens**.

### Creating a Token

```bash
# Mint a token named "ci-deploy" expiring in 30 days:
ssh gickers.sh token create "ci-deploy" --expires-in 30d

# Mint a token restricted to Git LFS and a specific repository:
ssh gickers.sh token create "lfs-sync" --scope lfs,repo:read --repo my-app

# Mint a single-use token that automatically revokes after its first use:
ssh gickers.sh token create "one-time-deploy" --single-use

# Mint a permanent token without expiration:
ssh gickers.sh token create "desktop-laptop"
```

The CLI prints your token secret:
```text
Access token created:
ss_98a72bf108420f8d...
Keep this secret safe — it will not be displayed again.
```

### Authenticating Git and Git LFS with a Token

```bash
# Clone using your token in the URL:
git clone https://ss_98a72bf108420f8d...@gickers.sh/alice/my-app.git

# Or enter your token when prompted for credentials:
git clone https://gickers.sh/alice/my-app.git
# Username for 'https://gickers.sh': token (or your username)
# Password for 'https://gickers.sh': <paste your token>

# Git LFS also natively accepts your token as a Bearer token:
# Authorization: Bearer ss_98a72bf108420f8d...
```

### Managing and Revoking Tokens

```bash
# List all active access tokens with scopes, repos, and expiry status:
ssh gickers.sh token list

# Revoke a token immediately by name or ID:
ssh gickers.sh token delete "ci-deploy"
ssh gickers.sh token delete 1
```
MD;
    }

    public static function repoManagementMarkdown(): string
    {
        return <<<'MD'
# Git Repositories: Creation, Clone & Workflow

**gickers.sh** is a full-featured Git repository host with support for SSH pack transport (`git-upload-pack`, `git-receive-pack`), Smart HTTP, Git LFS, and on-demand repository creation.

---

## 1. Creating a Repository

### Method A: Explicit Creation via SSH CLI

```bash
# Create a public repository:
ssh gickers.sh repo create backend-api --description "Core REST API"

# Create a private repository:
ssh gickers.sh repo create secret-project --private --description "Confidential"
```

### Method B: On-Demand Creation via `git push`

You do not need to create a repository beforehand. Simply push any existing local Git repository to **gickers.sh**, and the server will provision the repository on the fly:

```bash
cd my-local-app
git remote add origin ssh://gickers.sh/my-local-app.git
git push -u origin main
```

---

## 2. Cloning Repositories

### Over SSH (Recommended)

```bash
# If using the default SSH port (22):
git clone ssh://gickers.sh/alice/my-app.git
# Or using the shorthand:
git clone ssh://gickers.sh/my-app.git
```

### Over Smart HTTP

```bash
# Public repositories can be cloned anonymously:
git clone https://gickers.sh/alice/my-app.git

# Private repositories require a Personal Access Token:
git clone https://cst_token_secret@gickers.sh/alice/my-app.git
```

---

## 3. Managing Repository Settings

```bash
# Inspect repository metadata, branches, and commits
ssh gickers.sh repo info my-app

# Toggle private/public visibility
ssh gickers.sh repo private my-app true
ssh gickers.sh repo private my-app false

# Update description
ssh gickers.sh repo description my-app "Next-generation API platform"

# List all repository branches
ssh gickers.sh repo branch list my-app

# Inspect file trees and blob contents via CLI
ssh gickers.sh repo tree my-app main
ssh gickers.sh repo blob my-app main README.md
```

---

## 4. Deleting a Repository

```bash
ssh gickers.sh repo delete my-app
```
> **Warning:** Repository deletion is irreversible. All branches, commit history, and associated deployment environments will be permanently removed.
MD;
    }

    public static function deployGuideMarkdown(): string
    {
        return <<<'MD'
# Container Deployment Engine & Custom Domains

**gickers.sh** includes a built-in container deployment engine that turns any Git repository containing a `Dockerfile` or `compose.yaml` into live, production-grade microservices with automated TLS certificates via **Caddy**.

---

## 1. Enabling Deployments on a Branch

To configure a branch for automated deployments:

```bash
# Enable deployment for the main branch:
ssh gickers.sh repo deploy enable my-app main
```

When enabled:
- Pushing new commits to `main` triggers an automatic build and zero-downtime deployment.
- A dedicated, isolated container network and resource quota are assigned to the environment.

---

## 2. Supported Deployment Specifications

### Dockerfile Deployment
If your repository root contains a `Dockerfile`, **gickers.sh** builds the container image and binds port `80` (or the port specified in `EXPOSE`).

### Docker Compose (`compose.yaml` / `docker-compose.yml`)
For multi-container setups, **gickers.sh** supports standard Docker Compose specifications:
- Services (web, api, worker, redis)
- Resource quotas (CPU, memory limits, max PIDs)
- Healthchecks (`test`, `interval`, `timeout`, `retries`)
- Ephemeral and persistent named volumes

---

## 3. Custom Domains & Automated HTTPS

Each deployment environment can be mapped to one or multiple public domains:

```bash
# Map api.example.com to the "web" service on port 80:
ssh gickers.sh repo deploy domain add my-app main api.example.com --service web --port 80
```

### DNS Configuration
Point a `CNAME` or `A` record for your domain to your **gickers.sh** server IP or hostname:
```text
api.example.com.   IN   CNAME   gickers.sh.
```

**Caddy** automatically provisions and renews Let's Encrypt / ZeroSSL TLS certificates with zero manual intervention.

---

## 4. Encrypted Environment Variables

Store secrets (API keys, database URLs, tokens) encrypted with libsodium:

```bash
# Set an environment variable:
ssh gickers.sh repo deploy env set my-app main DATABASE_URL="postgres://..."
ssh gickers.sh repo deploy env set my-app main APP_ENV="production"

# List defined environment variable keys (values remain hidden):
ssh gickers.sh repo deploy env list my-app main

# Unset an environment variable:
ssh gickers.sh repo deploy env unset my-app main UNWANTED_VAR
```

---

## 5. Monitoring & Operational Commands

```bash
# Inspect environment status and current running release:
ssh gickers.sh repo deploy status my-app main

# Stream real-time container runtime logs:
ssh gickers.sh repo deploy logs my-app main --tail 100

# Restart running containers:
ssh gickers.sh repo deploy restart my-app main

# Manually trigger a redeployment from HEAD:
ssh gickers.sh repo deploy trigger my-app main
```
MD;
    }

    public static function collaborationMarkdown(): string
    {
        return <<<'MD'
# Collaboration, Permissions & Key Governance

**gickers.sh** provides cryptographic access control for teams, organizations, and open-source contributors.

---

## 1. Repository Collaborators & Roles

You can grant team members access to your repositories with three distinct permission tiers:

| Role | Permissions & Capabilities |
| :--- | :--- |
| **`read`** | Clone over SSH & HTTPS, view source code, commit history, and documentation. Cannot push. |
| **`write`** | Push commits, manage branches, trigger deployments, inspect build logs. |
| **`admin`** | Full repository control: manage collaborators, delete repositories, edit environment secrets. |

### Adding and Managing Collaborators

```bash
# Add bob with read-only access:
ssh gickers.sh repo collab add my-app bob read

# Add charlie with write access:
ssh gickers.sh repo collab add my-app charlie write

# List all current collaborators and their roles:
ssh gickers.sh repo collab list my-app

# Revoke collaborator access:
ssh gickers.sh repo collab remove my-app bob
```

---

## 2. Managing Multiple SSH Public Keys (`pubkey`)

You can associate multiple public keys with your account (work laptop, home desktop, CI deploy key):

```bash
# List all public keys linked to your account:
ssh gickers.sh pubkey list

# Add a secondary public key:
ssh gickers.sh pubkey add "ssh-ed25519 AAAAC3... alice@work-laptop"

# Remove an old or compromised public key:
ssh gickers.sh pubkey remove "ssh-ed25519 AAAAC3... alice@old-macbook"
```

---

## 3. Server Governance & Settings (Server Administrators)

Server administrators can inspect and update global instance policies:

```bash
# Inspect or toggle public self-service signup:
ssh gickers.sh settings allow-signup
ssh gickers.sh settings allow-signup true

# Inspect or configure deployment engine authorization policy:
ssh gickers.sh settings deploy-policy
```
MD;
    }
}
