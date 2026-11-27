#!/usr/bin/env bash
# ==============================================================================
#  gickers.sh — One-Shot Production Installer & Deployment Wizard
#  Inspired by Charmbracelet Gum (TokyoNight High Contrast Theme)
# ==============================================================================
set -euo pipefail

__GUM_LIB__

# Display Termstrap Header
cat << 'EOF'
__HEADER_CARD__
EOF

# Detect default script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd || echo "$PWD")"
if [ -f "$SCRIPT_DIR/compose.yaml" ]; then
    PROJECT_ROOT="$SCRIPT_DIR"
elif [ -f "$PWD/compose.yaml" ]; then
    PROJECT_ROOT="$PWD"
else
    PROJECT_ROOT=""
fi

# Pre-seeded parameters from generator
DOMAIN="__DOMAIN__"
APP_NAME="__APP_NAME__"
SSH_PORT="__SSH_PORT__"
HTTP_PORT="__HTTP_PORT__"
HTTPS_PORT="__HTTPS_PORT__"
INSTALL_DIR="__INSTALL_DIR__"
ADMIN_SSH_KEY="__ADMIN_KEY__"
ADMIN_USER="__ADMIN_USER__"
POSTGRES_USER="__POSTGRES_USER__"
POSTGRES_DB="__POSTGRES_DB__"
POSTGRES_PASSWORD="__POSTGRES_PASSWORD__"
ANON_ACCESS="__ANON_ACCESS__"
ALLOW_SIGNUP="__ALLOW_SIGNUP__"
ALLOW_KEYLESS="__ALLOW_KEYLESS__"
LFS_ENABLED="__LFS_ENABLED__"
NON_INTERACTIVE="__NON_INTERACTIVE__"
DRY_RUN="__DRY_RUN__"
SKIP_DOCKER=0
SKIP_SSHD_SWITCH=0

# Auto-detect root vs non-root
if [ "$(id -u 2>/dev/null || echo 1)" -eq 0 ]; then
    IS_ROOT=1
else
    IS_ROOT=0
fi

# Parse CLI arguments if passed
while [ $# -gt 0 ]; do
    case "$1" in
        --domain) DOMAIN="$2"; shift 2 ;;
        --dir) INSTALL_DIR="$2"; shift 2 ;;
        --ssh-port) SSH_PORT="$2"; shift 2 ;;
        --http-port) HTTP_PORT="$2"; shift 2 ;;
        --https-port) HTTPS_PORT="$2"; shift 2 ;;
        --app-name) APP_NAME="$2"; shift 2 ;;
        --admin-key) ADMIN_SSH_KEY="$2"; shift 2 ;;
        --admin-user) ADMIN_USER="$2"; shift 2 ;;
        --db-user) POSTGRES_USER="$2"; shift 2 ;;
        --db-name) POSTGRES_DB="$2"; shift 2 ;;
        --db-password) POSTGRES_PASSWORD="$2"; shift 2 ;;
        --anon-access) ANON_ACCESS="$2"; shift 2 ;;
        --allow-signup) ALLOW_SIGNUP="$2"; shift 2 ;;
        --allow-keyless) ALLOW_KEYLESS="$2"; shift 2 ;;
        --lfs) LFS_ENABLED="$2"; shift 2 ;;
        --skip-docker) SKIP_DOCKER=1; shift ;;
        --skip-sshd-switch) SKIP_SSHD_SWITCH=1; shift ;;
        -y|--yes|--non-interactive) NON_INTERACTIVE=1; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        -h|--help)
            echo "Usage: install [options]"
            echo ""
            echo "Options:"
            echo "  --domain <domain>         Public domain (default: $DOMAIN)"
            echo "  --dir <path>              Installation directory"
            echo "  --ssh-port <port>         Public Git SSH port (default: $SSH_PORT)"
            echo "  --http-port <port>        Public HTTP port (default: $HTTP_PORT)"
            echo "  --https-port <port>       Public HTTPS port (default: $HTTPS_PORT)"
            echo "  --app-name <name>         App branding (default: $APP_NAME)"
            echo "  --admin-key <pubkey>      Initial admin SSH public key"
            echo "  --admin-user <username>   Initial admin username"
            echo "  --db-user <user>          Postgres user (default: $POSTGRES_USER)"
            echo "  --db-name <name>          Postgres database (default: $POSTGRES_DB)"
            echo "  --db-password <password>  Postgres password"
            echo "  --anon-access <policy>    Anonymous access (read-only, no-access, public)"
            echo "  --allow-signup <bool>     Allow public signup (true/false)"
            echo "  --allow-keyless <bool>    Allow keyless connections (true/false)"
            echo "  --lfs <bool>              Enable Git LFS (true/false)"
            echo "  --skip-docker             Skip Docker CE installation"
            echo "  --skip-sshd-switch        Do not switch host sshd port from 22"
            echo "  -y, --yes                 Non-interactive mode with defaults"
            echo "  --dry-run                 Simulate deployment without changes"
            echo "  -h, --help                Show this help message"
            exit 0
            ;;
        *)
            printf "  %s✖%s %sOption inconnue : %s%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$1" "$C_RESET"
            exit 1
            ;;
    esac
done

# Resolve default install directory if empty
if [ -z "$INSTALL_DIR" ]; then
    if [ -n "$PROJECT_ROOT" ]; then
        INSTALL_DIR="$PROJECT_ROOT"
    elif [ "$IS_ROOT" -eq 1 ]; then
        INSTALL_DIR="/var/www/gickers"
    else
        INSTALL_DIR="$HOME/gickers.sh"
    fi
fi

# Detect OS
OS_NAME="linux"
OS_PRETTY="Linux"
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS_NAME="${ID:-linux}"
    OS_PRETTY="${PRETTY_NAME:-$OS_NAME}"
elif [ "$(uname -s 2>/dev/null)" = "Darwin" ]; then
    OS_NAME="darwin"
    OS_PRETTY="macOS $(sw_vers -productVersion 2>/dev/null || true)"
fi

if [ "$IS_ROOT" -eq 1 ]; then
    printf "  %sℹ%s %sPrivilèges : ${C_BOLD}${C_GREEN}root${C_RESET} %s(%s)%s\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$C_MUTED" "$OS_PRETTY" "$C_RESET"
else
    CURRENT_USER=$(whoami 2>/dev/null || echo "user")
    printf "  %sℹ%s %sPrivilèges : ${C_BOLD}${C_GOLD}%s${C_RESET} %s(%s, mode applicatif)%s\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$CURRENT_USER" "$C_MUTED" "$OS_PRETTY" "$C_RESET"
fi

# Auto-detect existing Admin SSH Public Key
DETECTED_ADMIN_KEY=""
if [ -z "$ADMIN_SSH_KEY" ]; then
    if [ -f "$HOME/.ssh/id_ed25519.pub" ]; then
        DETECTED_ADMIN_KEY=$(cat "$HOME/.ssh/id_ed25519.pub" 2>/dev/null || true)
    elif [ -f "$HOME/.ssh/id_rsa.pub" ]; then
        DETECTED_ADMIN_KEY=$(cat "$HOME/.ssh/id_rsa.pub" 2>/dev/null || true)
    elif [ -f "$HOME/.ssh/authorized_keys" ]; then
        DETECTED_ADMIN_KEY=$(head -n 1 "$HOME/.ssh/authorized_keys" 2>/dev/null || true)
    elif [ -f "/root/.ssh/authorized_keys" ] && [ "$IS_ROOT" -eq 1 ]; then
        DETECTED_ADMIN_KEY=$(head -n 1 "/root/.ssh/authorized_keys" 2>/dev/null || true)
    fi
fi

# ------------------------------------------------------------------------------
# Interactive Form (TokyoNight Gum Theme)
# ------------------------------------------------------------------------------
if [ "$NON_INTERACTIVE" -eq 0 ]; then
    # 1. Target Domain
    gum_input "Domaine public (FQDN)" DOMAIN "$DOMAIN" "ex: gits.run ou code.mon-domaine.com"
    DOMAIN=$(echo "$DOMAIN" | tr -d '[:space:]')

    # 2. Application Name
    gum_input "Nom d'usage de la forge" APP_NAME "$APP_NAME" "ex: Gickers.sh"

    # 3. Installation Directory
    gum_input "Répertoire d'installation" INSTALL_DIR "$INSTALL_DIR"
    INSTALL_DIR="${INSTALL_DIR/#\~/$HOME}"

    # 4. Git SSH Port
    SSH_OPTS=(
        "Port 22 (Standard Git - bascule automatique de OpenSSH vers 2222 si nécessaire)"
        "Port 2223 (Port alternatif sans modifier OpenSSH du système)"
        "Port personnalisé..."
    )
    gum_choose "Port SSH public pour Git" "${SSH_OPTS[@]}" && SSH_CHOICE=$? || SSH_CHOICE=$?
    case "$SSH_CHOICE" in
        0) SSH_PORT="22" ;;
        1) SSH_PORT="2223" ;;
        2) gum_input "Numéro de port SSH Git personnalisé" SSH_PORT "2222" ;;
    esac

    # 5. Web Ports (HTTP / HTTPS)
    HTTP_OPTS=(
        "Standard (Ports 80 & 443 avec certificats Let's Encrypt automatiques par Caddy)"
        "Personnaliser les ports Web (ex: derrière un reverse-proxy existant)"
    )
    gum_choose "Configuration des ports Web" "${HTTP_OPTS[@]}" && WEB_CHOICE=$? || WEB_CHOICE=$?
    if [ "$WEB_CHOICE" -eq 1 ]; then
        gum_input "Port HTTP public" HTTP_PORT "$HTTP_PORT"
        gum_input "Port HTTPS public" HTTPS_PORT "$HTTPS_PORT"
    fi

    # 6. Admin SSH Key
    KEY_OPTS=()
    if [ -n "$DETECTED_ADMIN_KEY" ]; then
        KEY_SHORT=$(echo "$DETECTED_ADMIN_KEY" | awk '{print $1, substr($2,1,16)"...", $3}')
        KEY_OPTS+=("Utiliser la clé détectée localement (${KEY_SHORT})")
    fi
    KEY_OPTS+=(
        "Générer une nouvelle clé SSH admin dédiée (ed25519)"
        "Coller le contenu d'une clé publique SSH (.pub)"
        "Configurer plus tard (le premier utilisateur connecté deviendra admin)"
    )

    gum_choose "Clé SSH de l'administrateur initial" "${KEY_OPTS[@]}" && KEY_CHOICE=$? || KEY_CHOICE=$?

    KEY_ACTION=""
    if [ -n "$DETECTED_ADMIN_KEY" ]; then
        case "$KEY_CHOICE" in
            0) ADMIN_SSH_KEY="$DETECTED_ADMIN_KEY" ;;
            1) KEY_ACTION="generate" ;;
            2) KEY_ACTION="paste" ;;
            3) ADMIN_SSH_KEY="" ;;
        esac
    else
        case "$KEY_CHOICE" in
            0) KEY_ACTION="generate" ;;
            1) KEY_ACTION="paste" ;;
            2) ADMIN_SSH_KEY="" ;;
        esac
    fi

    if [ "$KEY_ACTION" = "generate" ]; then
        ADMIN_KEY_PATH="$HOME/.ssh/gickers_admin_ed25519"
        prompt_key_path_with_collision_check "$ADMIN_KEY_PATH" ADMIN_KEY_PATH OVERWRITE_ADMIN_KEY
        mkdir -p "$(dirname "$ADMIN_KEY_PATH")" && chmod 700 "$(dirname "$ADMIN_KEY_PATH")"
        if [ "$OVERWRITE_ADMIN_KEY" -eq 1 ]; then
            prompt_password_with_confirm "Passphrase de la clé admin" ADMIN_PASSPHRASE
            ( ssh-keygen -t ed25519 -f "$ADMIN_KEY_PATH" -C "admin@${DOMAIN}" -N "$ADMIN_PASSPHRASE" >/dev/null 2>&1 ) &
            gum_spin $! "Génération de la clé SSH ed25519..."
        fi
        if [ -f "${ADMIN_KEY_PATH}.pub" ]; then
            ADMIN_SSH_KEY=$(cat "${ADMIN_KEY_PATH}.pub")
            printf "  %s✔%s %sClé admin configurée : ${BG_BADGE} ${C_BOLD}${C_GOLD}%s ${C_RESET}\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$ADMIN_KEY_PATH"
        fi
    elif [ "$KEY_ACTION" = "paste" ]; then
        gum_paste_pubkey ADMIN_SSH_KEY
    fi

    # 7. Admin Username
    DEFAULT_ADMIN_USER="${ADMIN_USER:-admin}"
    gum_input "Nom d'utilisateur administrateur" ADMIN_USER "$DEFAULT_ADMIN_USER"
    ADMIN_USER=$(echo "$ADMIN_USER" | tr -d '[:space:]')

    # 8. Server Access Policy
    POLICY_OPTS=(
        "Lecture seule publique (read-only) [Recommandé: dépôts publics visibles, écriture par SSH authentifié]"
        "Privé strict (no-access) [Tout accès anonyme interdit]"
        "Public complet (public)"
    )
    gum_choose "Politique d'accès anonyme" "${POLICY_OPTS[@]}" && POL_CHOICE=$? || POL_CHOICE=$?
    case "$POL_CHOICE" in
        0) ANON_ACCESS="read-only" ;;
        1) ANON_ACCESS="no-access" ;;
        2) ANON_ACCESS="public" ;;
    esac

    SIGNUP_OPTS=(
        "Inscriptions en libre-service activées (true) [Recommandé]"
        "Inscriptions fermées (false) [Serveur privé sur invitation]"
    )
    gum_choose "Inscriptions de nouveaux utilisateurs" "${SIGNUP_OPTS[@]}" && SIGN_CHOICE=$? || SIGN_CHOICE=$?
    [ "$SIGN_CHOICE" -eq 0 ] && ALLOW_SIGNUP="true" || ALLOW_SIGNUP="false"

    # 9. Summary & Confirmation
    printf "\n"
    printf "  %s%sConfiguration du déploiement :%s\n" "$C_BOLD" "$C_CYAN" "$C_RESET"
    printf "    • %sDomaine public :%s       %shttps://%s%s\n" "$C_MUTED" "$C_RESET" "$C_BOLD$C_WHITE" "$DOMAIN" "$C_RESET"
    printf "    • %sNom d'usage :%s          %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_BOLD$C_WHITE" "$APP_NAME" "$C_RESET"
    printf "    • %sRépertoire cible :%s     %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_GOLD" "$INSTALL_DIR" "$C_RESET"
    printf "    • %sPort SSH Git :%s         %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_CYAN" "$SSH_PORT" "$C_RESET"
    printf "    • %sPorts Web :%s            %s%s / %s%s\n" "$C_MUTED" "$C_RESET" "$C_CYAN" "$HTTP_PORT" "$HTTPS_PORT" "$C_RESET"
    printf "    • %sAdmin initial :%s        %s@%s%s\n" "$C_MUTED" "$C_RESET" "$C_GREEN" "$ADMIN_USER" "$C_RESET"
    printf "    • %sAccès anonyme :%s        %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_WHITE" "$ANON_ACCESS" "$C_RESET"
    printf "    • %sInscriptions :%s         %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_WHITE" "$ALLOW_SIGNUP" "$C_RESET"
    printf "\n"

    gum_confirm "Lancer l'installation et le déploiement sur cette machine ?" 1 || {
        printf "\n  %sℹ%s %sDéploiement annulé par l'utilisateur.%s\n\n" "$C_CYAN" "$C_RESET" "$C_MUTED" "$C_RESET"
        exit 0
    }
fi

if [ "$DRY_RUN" -eq 1 ]; then
    printf "\n  %s✔%s %s[DRY-RUN] Validation des paramètres réussie. Aucune modification effectuée.%s\n\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_GREEN" "$C_RESET"
    exit 0
fi

# ------------------------------------------------------------------------------
# System Dependencies & Docker Setup (if root)
# ------------------------------------------------------------------------------
if [ "$IS_ROOT" -eq 1 ] && [ "$SKIP_DOCKER" -eq 0 ]; then
    if command -v apt-get >/dev/null 2>&1; then
        (
            export DEBIAN_FRONTEND=noninteractive
            apt-get update -y >/dev/null 2>&1
            apt-get install -y --no-install-recommends \
                apt-transport-https ca-certificates curl gnupg git git-lfs jq ufw fail2ban openssl tar >/dev/null 2>&1
        ) &
        gum_spin $! "Vérification des dépendances système (apt)..."
    fi

    # Install Docker CE if missing
    if ! command -v docker >/dev/null 2>&1; then
        if [ -d /etc/apt ]; then
            (
                export DEBIAN_FRONTEND=noninteractive
                install -m 0755 -d /etc/apt/keyrings
                curl -fsSL "https://download.docker.com/linux/$OS_NAME/gpg" | gpg --dearmor -o /etc/apt/keyrings/docker.gpg --yes >/dev/null 2>&1
                chmod a+r /etc/apt/keyrings/docker.gpg
                CODENAME=$(. /etc/os-release && echo "$VERSION_CODENAME")
                [ -z "$CODENAME" ] && CODENAME="stable"
                echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/$OS_NAME $CODENAME stable" \
                    > /etc/apt/sources.list.d/docker.list
                apt-get update -y >/dev/null 2>&1
                apt-get install -y --no-install-recommends docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin >/dev/null 2>&1
                systemctl enable --now docker >/dev/null 2>&1 || true
            ) &
            gum_spin $! "Installation officielle de Docker CE & Docker Compose..."
        fi
    fi

    if [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != "root" ]; then
        usermod -aG docker "$SUDO_USER" 2>/dev/null || true
    fi

    # --------------------------------------------------------------------------
    # Host OpenSSH Transition (from 22 to 2222 if port 22 is targeted)
    # --------------------------------------------------------------------------
    if [ "$SSH_PORT" = "22" ] && [ "$SKIP_SSHD_SWITCH" -eq 0 ]; then
        SSH_OCCUPIED=$(ss -tlnp 2>/dev/null | grep -E ':22[[:space:]]' | grep -E 'sshd|systemd|ssh' || true)
        if [ -n "$SSH_OCCUPIED" ]; then
            (
                if command -v ufw >/dev/null 2>&1; then
                    ufw allow 2222/tcp comment 'System OpenSSH (moved from 22)' >/dev/null 2>&1 || true
                fi
                if [ -d /etc/ssh/sshd_config.d ]; then
                    cat > /etc/ssh/sshd_config.d/99-gickers-ssh-port.conf << 'EOF_SSHD'
# Configuré par gickers.sh pour libérer le port 22
Port 2222
EOF_SSHD
                    chmod 644 /etc/ssh/sshd_config.d/99-gickers-ssh-port.conf
                fi
                if [ -f /etc/ssh/sshd_config ]; then
                    sed -i -E 's/^[#[:space:]]*Port[[:space:]]+22[[:space:]]*$/Port 2222/' /etc/ssh/sshd_config
                fi
                if systemctl is-active --quiet ssh.socket 2>/dev/null; then
                    mkdir -p /etc/systemd/system/ssh.socket.d
                    cat > /etc/systemd/system/ssh.socket.d/listen-2222.conf << 'EOF_SOCKET'
[Socket]
ListenStream=
ListenStream=2222
EOF_SOCKET
                    systemctl daemon-reload >/dev/null 2>&1
                    systemctl restart ssh.socket >/dev/null 2>&1 || true
                else
                    systemctl restart sshd >/dev/null 2>&1 || systemctl restart ssh >/dev/null 2>&1 || true
                fi
            ) &
            gum_spin $! "Bascule sécurisée de OpenSSH hôte vers le port 2222 (port 22 libéré)..."

            printf "\n  %s!%s %s[IMPORTANT] OpenSSH hôte écoute désormais sur le port 2222 :%s\n" "$C_GOLD" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
            printf "    %sssh -p 2222 $(whoami)@<IP_VPS>%s\n\n" "$C_CYAN" "$C_RESET"
        fi
    fi

    # Firewall UFW
    if command -v ufw >/dev/null 2>&1; then
        (
            CURRENT_SSH=$(ss -tlnp 2>/dev/null | grep -E 'sshd|ssh' | awk '{print $4}' | awk -F: '{print $NF}' | head -n 1 || echo "2222")
            [ -z "$CURRENT_SSH" ] && CURRENT_SSH="2222"
            ufw allow "$CURRENT_SSH"/tcp comment 'Host SSH daemon' >/dev/null 2>&1 || true
            ufw allow "$HTTP_PORT"/tcp comment 'Gickers HTTP' >/dev/null 2>&1 || true
            ufw allow "$HTTPS_PORT"/tcp comment 'Gickers HTTPS' >/dev/null 2>&1 || true
            if [ "$SSH_PORT" != "$CURRENT_SSH" ]; then
                ufw allow "$SSH_PORT"/tcp comment 'Gickers Git SSH' >/dev/null 2>&1 || true
            fi
            ufw --force enable >/dev/null 2>&1 || true
        ) &
        gum_spin $! "Configuration des règles de pare-feu UFW..."
    fi
fi

# ------------------------------------------------------------------------------
# Application Directory & Repository Sync
# ------------------------------------------------------------------------------
mkdir -p "$INSTALL_DIR"
cd "$INSTALL_DIR"

if [ ! -f "$INSTALL_DIR/compose.yaml" ]; then
    if [ -n "$PROJECT_ROOT" ] && [ -f "$PROJECT_ROOT/compose.yaml" ] && [ "$PROJECT_ROOT" != "$INSTALL_DIR" ]; then
        (
            tar --exclude='.git' --exclude='.data' --exclude='var' -cf - -C "$PROJECT_ROOT" . | tar -xf - -C "$INSTALL_DIR"
        ) &
        gum_spin $! "Copie des fichiers sources vers $INSTALL_DIR..."
    else
        (
            git clone --depth 1 https://github.com/gickers/gickers.sh.git "$INSTALL_DIR" >/dev/null 2>&1 || true
        ) &
        gum_spin $! "Téléchargement du projet gickers.sh..."
    fi
fi

mkdir -p .data .data/caddy/sites var var/cache var/log
chmod 755 .data .data/caddy/sites var 2>/dev/null || true

# ------------------------------------------------------------------------------
# Production Environment (.env.docker)
# ------------------------------------------------------------------------------
APP_SECRET=$(openssl rand -hex 16 2>/dev/null || date +%s%N | sha256sum | head -c 32)
if [ -z "$POSTGRES_PASSWORD" ]; then
    POSTGRES_PASSWORD=$(openssl rand -base64 24 2>/dev/null | tr -dc 'a-zA-Z0-9' | head -c 24 || echo "gickers_secret_pass_$(date +%s)")
fi

if [ -f .env.docker ]; then
    cp .env.docker .env.docker.bak
    EXISTING_SECRET=$(grep -E '^APP_SECRET=' .env.docker | cut -d '=' -f2- || true)
    EXISTING_PG_PASS=$(grep -E '^POSTGRES_PASSWORD=' .env.docker | cut -d '=' -f2- || true)
    if [ -n "$EXISTING_SECRET" ] && [ "$EXISTING_SECRET" != "change-me" ]; then
        APP_SECRET="$EXISTING_SECRET"
    fi
    if [ -n "$EXISTING_PG_PASS" ] && [ "$EXISTING_PG_PASS" != "gickers" ]; then
        POSTGRES_PASSWORD="$EXISTING_PG_PASS"
    fi
fi

if [ "$SSH_PORT" = "22" ]; then
    SOFT_SERVE_SSH_URL="ssh://${DOMAIN}"
else
    SOFT_SERVE_SSH_URL="ssh://${DOMAIN}:${SSH_PORT}"
fi

cat > .env.docker << EOF
# ==============================================================================
# Production Environment for Gickers.sh (Domain: ${DOMAIN})
# Generated automatically on $(date -u +"%Y-%m-%dT%H:%M:%SZ" 2>/dev/null || date)
# ==============================================================================

APP_ENV=prod
APP_DEBUG=0
APP_SECRET=${APP_SECRET}

# Database
POSTGRES_USER=${POSTGRES_USER}
POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
POSTGRES_DB=${POSTGRES_DB}

# Host Ports
HTTP_PORT=${HTTP_PORT}
HTTPS_PORT=${HTTPS_PORT}
SSH_PORT=${SSH_PORT}

# Public Host & URLs (Production Target: ${DOMAIN})
APP_DOMAIN=${DOMAIN}
SOFT_SERVE_HTTP_PUBLIC_URL=https://${DOMAIN}
SOFT_SERVE_SSH_PUBLIC_URL=${SOFT_SERVE_SSH_URL}

# TLS (Empty for automated Let's Encrypt certificates managed by Caddy)
CADDY_TLS=""

# Server branding & policy
SOFT_SERVE_NAME=${APP_NAME}
SOFT_SERVE_LFS_ENABLED=${LFS_ENABLED}
SOFT_SERVE_ANON_ACCESS=${ANON_ACCESS}
SOFT_SERVE_ALLOW_KEYLESS=${ALLOW_KEYLESS}
SOFT_SERVE_INITIAL_ADMIN_KEYS="${ADMIN_SSH_KEY}"
SOFT_SERVE_WEBHOOK_ALLOW_LIST=

# Isolated Deploy Engine (Docker-in-Docker with gVisor sandbox & anti-SSRF)
DOCKER_HOST=tcp://deploy-dockerd:2375
DEPLOY_GATEWAY_HOST=deploy-dockerd
DEPLOY_DOCKER_RUNTIME=runsc
DEPLOY_GVISOR_PLATFORM=systrap
EOF

chmod 600 .env.docker 2>/dev/null || true
printf "  %s✔%s %sConfiguration .env.docker initialisée.%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"

# ------------------------------------------------------------------------------
# Docker Build & Deployment
# ------------------------------------------------------------------------------
( docker compose --env-file .env.docker build >/dev/null 2>&1 ) &
gum_spin $! "Construction des conteneurs Docker (PHP, gickers-ssh, FrankenPHP)..."

( docker compose --env-file .env.docker up -d postgres >/dev/null 2>&1 ) &
gum_spin $! "Démarrage de PostgreSQL..."

# Wait for PostgreSQL
RETRY=0
MAX_RETRY=30
until docker compose --env-file .env.docker exec -T postgres pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB" >/dev/null 2>&1 || [ $RETRY -eq $MAX_RETRY ]; do
    sleep 1
    RETRY=$((RETRY + 1))
done

( docker compose --env-file .env.docker run --rm init >/dev/null 2>&1 ) &
gum_spin $! "Exécution des migrations Doctrine & initialisation du serveur..."

( docker compose --env-file .env.docker up -d >/dev/null 2>&1 ) &
gum_spin $! "Démarrage complet de la stack (HTTP, SSH, Worker, Deploy-Engine)..."

# ------------------------------------------------------------------------------
# Systemd Service (if root)
# ------------------------------------------------------------------------------
if [ "$IS_ROOT" -eq 1 ]; then
    cat > /etc/systemd/system/gickers.service << EOF
[Unit]
Description=Gickers.sh Git Forge & Container Platform
Requires=docker.service
After=docker.service network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=${INSTALL_DIR}
ExecStart=/usr/bin/docker compose --env-file .env.docker up -d
ExecStop=/usr/bin/docker compose --env-file .env.docker down
ExecReload=/usr/bin/docker compose --env-file .env.docker restart
TimeoutStartSec=300

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload >/dev/null 2>&1 || true
    systemctl enable gickers.service >/dev/null 2>&1 || true

    if [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != "root" ]; then
        chown -R "$SUDO_USER":"$SUDO_USER" "$INSTALL_DIR" 2>/dev/null || true
    fi
fi

# ------------------------------------------------------------------------------
# Verification & Display Success Card
# ------------------------------------------------------------------------------
sleep 2
echo ""
cat << 'EOF'
__SUCCESS_CARD__
EOF
echo ""
