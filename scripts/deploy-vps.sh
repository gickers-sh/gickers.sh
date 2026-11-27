#!/usr/bin/env bash
# ==============================================================================
#  gickers.sh (hosted on gits.run) — Production VPS Deployment Script
# ==============================================================================
#  Automated deployment of gickers.sh Git forge & container platform:
#    - System dependency verification & installation (Docker CE, Git LFS, UFW, fail2ban)
#    - Firewall configuration (ports 80, 443, SSH)
#    - Production environment configuration (.env.docker)
#    - Automated Docker Compose build & database migrations
#    - Systemd service integration for auto-restart on boot
#    - Healthcheck and post-deployment validation
# ==============================================================================

set -euo pipefail

# Colors for terminal output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

log_info() {
    echo -e "${BLUE}[INFO]${NC} $*"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $*"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $*"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $*" >&2
}

banner() {
    cat << "EOF"
  ____ _      _                    _     
 / ___(_) ___| | _____ _ __ ___   ___| |__  
| |  _| |/ __| |/ / _ \ '__/ __| / __| '_ \ 
| |_| | | (__|   <  __/ |  \__ \_\__ \ | | |
 \____|_|\___|_|\_\___|_|  |___(_)___/_| |_|
   Production VPS Deployment (Target: gits.run)
EOF
}

# Detect repository root directory from script location
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Defaults (can be overridden via environment variables or CLI options)
DOMAIN="${DOMAIN:-gits.run}"
APP_NAME="${APP_NAME:-Gickers.sh}"
# By default, use current project directory (PWD/CWD); can be set explicitly via --dir
INSTALL_DIR="${INSTALL_DIR:-$SCRIPT_DIR}"
SSH_PORT="${SSH_PORT:-2223}"
HTTP_PORT="${HTTP_PORT:-80}"
HTTPS_PORT="${HTTPS_PORT:-443}"
POSTGRES_USER="${POSTGRES_USER:-gickers}"
POSTGRES_DB="${POSTGRES_DB:-gickers}"
ADMIN_SSH_KEY="${ADMIN_SSH_KEY:-}"
NON_INTERACTIVE="${NON_INTERACTIVE:-0}"
DRY_RUN="${DRY_RUN:-0}"

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)
            DOMAIN="$2"
            shift 2
            ;;
        --dir)
            INSTALL_DIR="$2"
            shift 2
            ;;
        --ssh-port)
            SSH_PORT="$2"
            shift 2
            ;;
        --admin-key)
            ADMIN_SSH_KEY="$2"
            shift 2
            ;;
        -y|--yes|--non-interactive)
            NON_INTERACTIVE=1
            shift
            ;;
        --dry-run)
            DRY_RUN=1
            shift
            ;;
        -h|--help)
            echo "Usage: $0 [options]"
            echo ""
            echo "Options:"
            echo "  --domain <domain>        Public domain name (default: gits.run)"
            echo "  --dir <path>             Installation directory (default: current project directory)"
            echo "  --ssh-port <port>        Public SSH port for Git/CLI (default: 2223)"
            echo "  --admin-key <pubkey>     Initial admin SSH public key"
            echo "  -y, --non-interactive    Run non-interactively with defaults"
            echo "  --dry-run                Simulate deployment without applying changes"
            echo "  -h, --help               Show this help message"
            exit 0
            ;;
        *)
            log_error "Unknown parameter: $1"
            exit 1
            ;;
    esac
done

banner

# ------------------------------------------------------------------------------
# 1. Privileges & OS Check
# ------------------------------------------------------------------------------
log_info "Vérification des prérequis système..."

if [[ $EUID -ne 0 ]]; then
    if [[ "$DRY_RUN" -eq 1 ]]; then
        log_warn "[DRY-RUN] Exécution en tant qu'utilisateur non-root ($(whoami)). Le déploiement réel nécessitera sudo/root."
    else
        log_error "Ce script doit être exécuté en tant que root (ou via sudo)."
        exit 1
    fi
fi

if [[ -f /etc/os-release ]]; then
    . /etc/os-release
    OS_NAME="$ID"
    OS_VERSION="$VERSION_ID"
    log_info "Système d'exploitation détecté : $PRETTY_NAME ($OS_NAME $OS_VERSION)"
else
    log_warn "Fichier /etc/os-release introuvable. Système non standard."
fi

# ------------------------------------------------------------------------------
# 2. Configuration & Admin Keys
# ------------------------------------------------------------------------------
log_info "Configuration du déploiement :"
echo "  • Domaine cible         : $DOMAIN"
echo "  • Nom d'usage / Marque  : $APP_NAME"
echo "  • Répertoire d'install  : $INSTALL_DIR"
echo "  • Port SSH public Git   : $SSH_PORT"
echo "  • Ports HTTP / HTTPS    : $HTTP_PORT / $HTTPS_PORT"

# Auto-detect admin SSH public key if not provided
if [[ -z "$ADMIN_SSH_KEY" ]]; then
    if [[ -f /root/.ssh/authorized_keys ]]; then
        ADMIN_SSH_KEY=$(head -n 1 /root/.ssh/authorized_keys || true)
    elif [[ -n "${SUDO_USER:-}" && -f "/home/$SUDO_USER/.ssh/authorized_keys" ]]; then
        ADMIN_SSH_KEY=$(head -n 1 "/home/$SUDO_USER/.ssh/authorized_keys" || true)
    fi
fi

if [[ -z "$ADMIN_SSH_KEY" && "$NON_INTERACTIVE" -eq 0 ]]; then
    echo ""
    echo -e "${YELLOW}Aucune clé SSH admin détectée automatiquement.${NC}"
    read -r -p "Entrez votre clé SSH publique (ex: ssh-ed25519 AAAAC3... vous@laptop) : " user_key
    ADMIN_SSH_KEY="$user_key"
fi

if [[ -n "$ADMIN_SSH_KEY" ]]; then
    log_info "Clé SSH admin configurée : $(echo "$ADMIN_SSH_KEY" | awk '{print $1, substr($2,1,16)"..." ,$3}')"
else
    log_warn "Aucune clé admin fournie. Le premier utilisateur connecté par SSH pourra initialiser le serveur."
fi

if [[ "$NON_INTERACTIVE" -eq 0 ]]; then
    echo ""
    read -r -p "Continuer le déploiement sur ce VPS ? [O/n] " confirm
    if [[ "$confirm" =~ ^[Nn] ]]; then
        log_info "Déploiement annulé."
        exit 0
    fi
fi

if [[ "$DRY_RUN" -eq 1 ]]; then
    log_success "[DRY-RUN] Validation réussie. Aucune modification effectuée."
    exit 0
fi

# ------------------------------------------------------------------------------
# 3. Installation des paquets système
# ------------------------------------------------------------------------------
log_info "Mise à jour des dépôts et installation des dépendances système..."

export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y --no-install-recommends \
    apt-transport-https \
    ca-certificates \
    curl \
    gnupg \
    git \
    git-lfs \
    jq \
    ufw \
    fail2ban \
    openssl \
    tar

# ------------------------------------------------------------------------------
# 4. Installation officielle de Docker CE & Docker Compose Plugin
# ------------------------------------------------------------------------------
if ! command -v docker >/dev/null 2>&1; then
    log_info "Installation de Docker CE officiel..."
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/"$OS_NAME"/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg --yes
    chmod a+r /etc/apt/keyrings/docker.gpg

    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/$OS_NAME $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
        > /etc/apt/sources.list.d/docker.list

    apt-get update -y
    apt-get install -y --no-install-recommends \
        docker-ce \
        docker-ce-cli \
        containerd.io \
        docker-buildx-plugin \
        docker-compose-plugin
    log_success "Docker CE installé avec succès."
else
    log_info "Docker est déjà installé ($(docker --version))."
fi

systemctl enable --now docker

# ------------------------------------------------------------------------------
# 5. Configuration du Pare-feu (UFW)
# ------------------------------------------------------------------------------
log_info "Configuration des règles de pare-feu UFW..."

# Preserve current SSH port
CURRENT_SSH_PORT=$(ss -tlnp 2>/dev/null | grep -E 'sshd|ssh' | awk '{print $4}' | awk -F: '{print $NF}' | head -n 1 || echo "22")
if [[ -z "$CURRENT_SSH_PORT" ]]; then
    CURRENT_SSH_PORT="22"
fi

log_info "Autorisation du port SSH système ($CURRENT_SSH_PORT/tcp)..."
ufw allow "$CURRENT_SSH_PORT"/tcp comment 'Host SSH daemon' || true

# Allow HTTP & HTTPS
log_info "Autorisation des ports Web (80/tcp, 443/tcp)..."
ufw allow "$HTTP_PORT"/tcp comment 'Gickers HTTP / ACME' || true
ufw allow "$HTTPS_PORT"/tcp comment 'Gickers HTTPS' || true

# Allow Gickers SSH port if different from system SSH
if [[ "$SSH_PORT" != "$CURRENT_SSH_PORT" ]]; then
    log_info "Autorisation du port Git SSH gickers ($SSH_PORT/tcp)..."
    ufw allow "$SSH_PORT"/tcp comment 'Gickers Git SSH' || true
fi

# Enable UFW non-interactively
ufw --force enable || true
log_success "Pare-feu configuré."

# ------------------------------------------------------------------------------
# 6. Déploiement des fichiers de l'application
# ------------------------------------------------------------------------------
log_info "Préparation du répertoire d'application $INSTALL_DIR..."
mkdir -p "$INSTALL_DIR"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ "$SCRIPT_DIR" != "$INSTALL_DIR" ]]; then
    log_info "Copie des fichiers du projet depuis $SCRIPT_DIR vers $INSTALL_DIR..."
    tar --exclude='.git' --exclude='.data' --exclude='var' -cf - -C "$SCRIPT_DIR" . | tar -xf - -C "$INSTALL_DIR"
fi

cd "$INSTALL_DIR"

# Ensure data directories exist with correct permissions
mkdir -p .data .data/caddy/sites var var/cache var/log
chmod 755 .data .data/caddy/sites var

# ------------------------------------------------------------------------------
# 7. Génération des secrets et du fichier .env.docker
# ------------------------------------------------------------------------------
log_info "Génération de la configuration .env.docker de production..."

APP_SECRET=$(openssl rand -hex 16)
POSTGRES_PASSWORD=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 24)

# Keep existing secrets if .env.docker already exists
if [[ -f .env.docker ]]; then
    log_info "Un fichier .env.docker existant a été détecté. Sauvegarde dans .env.docker.bak..."
    cp .env.docker .env.docker.bak
    EXISTING_SECRET=$(grep -E '^APP_SECRET=' .env.docker | cut -d '=' -f2- || true)
    EXISTING_PG_PASS=$(grep -E '^POSTGRES_PASSWORD=' .env.docker | cut -d '=' -f2- || true)
    if [[ -n "$EXISTING_SECRET" && "$EXISTING_SECRET" != "change-me" ]]; then
        APP_SECRET="$EXISTING_SECRET"
    fi
    if [[ -n "$EXISTING_PG_PASS" && "$EXISTING_PG_PASS" != "gickers" ]]; then
        POSTGRES_PASSWORD="$EXISTING_PG_PASS"
    fi
fi

cat > .env.docker << EOF
# ==============================================================================
# Production Environment for Gickers.sh (Domain: ${DOMAIN})
# Generated automatically on $(date -u +"%Y-%m-%dT%H:%M:%SZ")
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
SOFT_SERVE_SSH_PUBLIC_URL=ssh://${DOMAIN}:${SSH_PORT}

# TLS (Empty for automated Let's Encrypt certificates managed by Caddy)
CADDY_TLS=""

# Server branding & policy
SOFT_SERVE_NAME=${APP_NAME}
SOFT_SERVE_LFS_ENABLED=true
SOFT_SERVE_ANON_ACCESS=read-only
SOFT_SERVE_ALLOW_KEYLESS=true
SOFT_SERVE_INITIAL_ADMIN_KEYS="${ADMIN_SSH_KEY}"
SOFT_SERVE_WEBHOOK_ALLOW_LIST=

# Isolated Deploy Engine (Docker-in-Docker with gVisor sandbox & anti-SSRF)
DOCKER_HOST=tcp://deploy-dockerd:2375
DEPLOY_GATEWAY_HOST=deploy-dockerd
DEPLOY_DOCKER_RUNTIME=runsc
DEPLOY_GVISOR_PLATFORM=systrap
EOF

chmod 600 .env.docker
log_success "Fichier .env.docker configuré."

# ------------------------------------------------------------------------------
# 8. Build et initialisation des conteneurs
# ------------------------------------------------------------------------------
log_info "Construction des images Docker gickers-sh..."
docker compose --env-file .env.docker build

log_info "Démarrage de la base de données PostgreSQL..."
docker compose --env-file .env.docker up -d postgres

log_info "Attente de la disponibilité de PostgreSQL..."
RETRY=0
MAX_RETRY=30
until docker compose --env-file .env.docker exec -T postgres pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB" >/dev/null 2>&1 || [[ $RETRY -eq $MAX_RETRY ]]; do
    sleep 2
    RETRY=$((RETRY + 1))
done

if [[ $RETRY -eq $MAX_RETRY ]]; then
    log_error "PostgreSQL n'a pas répondu dans les délais impartis."
    exit 1
fi
log_success "PostgreSQL est prêt."

log_info "Exécution du conteneur d'initialisation (migrations & boostrap)..."
docker compose --env-file .env.docker run --rm init

log_info "Démarrage complet de la stack (HTTP, SSH, Worker, Deploy-Engine)..."
docker compose --env-file .env.docker up -d

# ------------------------------------------------------------------------------
# 9. Service Systemd pour persistance au démarrage du VPS
# ------------------------------------------------------------------------------
log_info "Configuration du service systemd gickers.service..."

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

systemctl daemon-reload
systemctl enable gickers.service
log_success "Service systemd gickers.service activé."

# ------------------------------------------------------------------------------
# 10. Validation & Résumé
# ------------------------------------------------------------------------------
sleep 5
log_info "État des conteneurs :"
docker compose --env-file .env.docker ps

echo ""
echo -e "${GREEN}==============================================================================${NC}"
echo -e "${BOLD}${GREEN}  Déploiement de gickers.sh réussi avec succès sur le VPS !${NC}"
echo -e "${GREEN}==============================================================================${NC}"
echo ""
echo -e "  ${BOLD}Nom d'usage :${NC}     ${CYAN}${APP_NAME}${NC}"
echo -e "  ${BOLD}Domaine :${NC}         ${CYAN}https://${DOMAIN}${NC}"
echo -e "  ${BOLD}Clone HTTP :${NC}      ${CYAN}https://${DOMAIN}/<user>/<repo>.git${NC}"
if [[ "$SSH_PORT" == "22" ]]; then
    echo -e "  ${BOLD}Clone SSH :${NC}       ${CYAN}git clone ssh://${DOMAIN}/<user>/<repo>.git${NC}"
    echo -e "  ${BOLD}Accès CLI/TUI :${NC}   ${CYAN}ssh ${DOMAIN}${NC}"
    echo -e "  ${BOLD}Info serveur :${NC}    ${CYAN}ssh ${DOMAIN} info${NC}"
else
    echo -e "  ${BOLD}Clone SSH :${NC}       ${CYAN}git clone ssh://${DOMAIN}:${SSH_PORT}/<user>/<repo>.git${NC}"
    echo -e "  ${BOLD}Accès CLI/TUI :${NC}   ${CYAN}ssh -p ${SSH_PORT} ${DOMAIN}${NC}"
    echo -e "  ${BOLD}Info serveur :${NC}    ${CYAN}ssh -p ${SSH_PORT} ${DOMAIN} info${NC}"
fi
echo ""
echo -e "  ${BOLD}Administration du service :${NC}"
echo -e "    • Statut stack :       ${YELLOW}docker compose -f ${INSTALL_DIR}/compose.yaml ps${NC}"
echo -e "    • Voir les logs :      ${YELLOW}docker compose -f ${INSTALL_DIR}/compose.yaml logs -f${NC}"
echo -e "    • Redémarrer :         ${YELLOW}systemctl restart gickers${NC}"
echo -e "    • Fichier config :     ${YELLOW}${INSTALL_DIR}/.env.docker${NC}"
echo ""
echo -e "  ${BOLD}Rappels DNS :${NC}"
echo -e "    Assurez-vous que l'enregistrement DNS A ou CNAME pour ${BOLD}${DOMAIN}${NC} pointe bien"
echo -e "    vers l'adresse IP publique de ce serveur VPS pour que Caddy délivre"
echo -e "    automatiquement le certificat SSL Let's Encrypt."
echo ""
EOF
chmod +x /Users/duck/app/gophp/codes.sh/scripts/deploy-vps.sh