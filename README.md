# codes.sh

Portage PHP 8.5 / Symfony 8.1 de Soft Serve (`../codes.sh.src`) : serveur Git auto-hébergé accessible en SSH (CLI + TUI), HTTP (smart/dumb, Git LFS) et `git://`.

Architecture : monolithe modulaire (`src/<Module>/{Domain,Application,Infrastructure}` + `src/UI/{Ssh,Console,Http,Tui,GitDaemon}`), décrite dans [`specs/symfony-modular-architecture/TECH.md`](specs/symfony-modular-architecture/TECH.md). Les dépendances entre couches sont vérifiées par deptrac.

## Prérequis

- PHP 8.5 avec `pdo_sqlite` (ou `pdo_pgsql`), `pcntl`, `sodium`, `ffi` (TUI / Termstrap)
- Composer, git, OpenSSH (`sshd`, `ssh-keygen`)
- [FrankenPHP](https://frankenphp.dev) pour le HTTP en production ; sans lui, `soft serve` se replie sur `php -S` (développement)

## Démarrer

```sh
make install
make run          # sshd :2223, HTTP :23232, git daemon :9418, worker Messenger
```

`make run` enregistre `~/.ssh/id_ed25519.pub` (ou `id_rsa.pub`) comme clé de l'utilisateur `admin` :

```sh
ssh -p 2223 localhost repo create demo
git push ssh://localhost:2223/demo main
git clone http://localhost:23232/demo.git
git clone git://localhost:9418/demo
ssh -t -p 2223 localhost        # TUI
```

`soft serve` prépare le répertoire de données, applique les migrations Doctrine, crée l'admin, génère les clés (hôte SSH, JWT LFS), `authorized_keys` et `sshd_config`, puis supervise `sshd`, le serveur HTTP, `soft:git-daemon` et `messenger:consume async`. `--dry-run` prépare tout sans lancer les processus.

## Configuration

Variables d'environnement (valeurs par défaut dans `.env`, surcharges locales dans `.env.local`) :

| Variable | Rôle |
|---|---|
| `SOFT_SERVE_DATA_PATH` | données : `repos/`, `lfs/`, `ssh/`, `hooks/`, `codeintel/` (défaut `.data`) |
| `DATABASE_URL` | SQLite ou PostgreSQL (défaut `.data/codes-sh.db`) |
| `SOFT_SERVE_SSH_LISTEN_ADDR`, `SOFT_SERVE_SSH_PUBLIC_URL` | écoute et URL publique SSH |
| `SOFT_SERVE_HTTP_LISTEN_ADDR`, `SOFT_SERVE_HTTP_PUBLIC_URL` | écoute HTTP ; l'URL publique sert d'émetteur JWT et de `href` LFS |
| `SOFT_SERVE_GIT_LISTEN_ADDR`, `SOFT_SERVE_GIT_MAX_CONNECTIONS` | git daemon |
| `SOFT_SERVE_ANON_ACCESS`, `SOFT_SERVE_ALLOW_KEYLESS` | valeurs initiales ; modifiables ensuite via `ssh … settings` |
| `SOFT_SERVE_INITIAL_ADMIN_KEYS` | clés publiques admin (une par ligne) |
| `SOFT_SERVE_LFS_ENABLED`, `SOFT_SERVE_NAME` | LFS, nom affiché |

## Commandes

| `bin/soft` | Équivalent `bin/console` |
|---|---|
| `soft serve [--sync-hooks] [--dry-run]` | `soft:serve` |
| `soft browse [chemin]` / `soft tui` | `soft:browse` / `soft:tui` |
| `soft admin bootstrap` | `soft:admin:bootstrap` |
| `soft admin migrate` / `rollback` | `doctrine:migrations:migrate` / `… prev` |
| `soft admin sync-hooks` | `soft:admin:sync-hooks` |
| `soft admin reindex-codeintel [dépôt]` | `soft:admin:reindex-codeintel` |
| `soft hook <nom>` | `soft:hook:<nom>` (appelé par les hooks git) |
| `soft man` | `soft:man` |

Les commandes SSH (`repo …`, `user …`, `pr …`, `token …`) ne sont pas dans `bin/console` : elles passent par `soft:ssh:dispatch`, la commande forcée de `authorized_keys`.

## Données existantes

Le schéma a été repensé : l'ancienne base `.data/soft-serve.db` n'est pas reprise. Pour repartir de zéro (`soft serve` recrée le répertoire de données, migre la base et l'admin) :

```sh
make clean-all && make run
```

Les dépôts nus restés sur disque ne réapparaissent qu'une fois réenregistrés.

## Développement

```sh
make test     # PHPUnit
make lint     # php-cs-fixer (dry-run), PHPStan niveau max, deptrac, lint conteneur/YAML
make cs       # corrige le style
make e2e      # soft serve sur ports/données jetables : repo create, push SSH, clone HTTP et git://
```
