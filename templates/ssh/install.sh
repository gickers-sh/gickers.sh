#!/usr/bin/env bash
# ==============================================================================
#  gickers.sh — One-Shot Production Installer & Deployment Wizard
#  Inspired by Charmbracelet Gum (TokyoNight High Contrast Theme)
# ==============================================================================
set -euo pipefail

__GUM_LIB__

main() {
# Display Termstrap Header
cat << 'EOF'
__HEADER_CARD__
EOF

# Detect default script directory
if [ -n "${BASH_SOURCE[0]:-}" ]; then
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd || echo "$PWD")"
else
    SCRIPT_DIR="$PWD"
fi
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
MODE="__MODE__"
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
USE_SUDO="__USE_SUDO__"
STEP_DOCKER="__STEP_DOCKER__"
STEP_SSHD="__STEP_SSHD__"
STEP_FIREWALL="__STEP_FIREWALL__"
STEP_SYSTEMD="__STEP_SYSTEMD__"
SYSTEM_USER="__SYSTEM_USER__"
SYSTEM_ONLY="__SYSTEM_ONLY__"
HARD_RESET="${HARD_RESET:-__HARD_RESET__}"
ADMIN_CLAIM_TOKEN=""

# Auto-detect root vs non-root
if [ "$(id -u 2>/dev/null || echo 1)" -eq 0 ]; then
    IS_ROOT=1
    SUDO_CMD=""
else
    IS_ROOT=0
    SUDO_CMD=""
fi

# Parse CLI arguments if passed
while [ $# -gt 0 ]; do
    case "$1" in
        --domain) DOMAIN="$2"; shift 2 ;;
        --dir) INSTALL_DIR="$2"; shift 2 ;;
        --mode|-m) MODE="$2"; shift 2 ;;
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
        --sudo) USE_SUDO=1; shift ;;
        --step-docker) STEP_DOCKER="$2"; shift 2 ;;
        --step-sshd) STEP_SSHD="$2"; shift 2 ;;
        --step-firewall) STEP_FIREWALL="$2"; shift 2 ;;
        --step-systemd) STEP_SYSTEMD="$2"; shift 2 ;;
        --system-user) SYSTEM_USER="$2"; shift 2 ;;
        --system-only) SYSTEM_ONLY=1; shift ;;
        --skip-docker) STEP_DOCKER="skip"; shift ;;
        --skip-sshd-switch) STEP_SSHD="skip"; shift ;;
        --skip-firewall) STEP_FIREWALL="skip"; shift ;;
        --skip-systemd) STEP_SYSTEMD="skip"; shift ;;
        --hard-reset|--reset-data) HARD_RESET=1; shift ;;
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
            echo "  --sudo                    Enable sudo mode for system operations"
            echo "  --system-only             Configure only host system steps (Docker, SSH 22, UFW, systemd)"
            echo "  --hard-reset              Wipe all Docker volumes, database, and repository data (Double confirmation in interactive mode)"
            echo "  --step-docker <mode>      Docker step: auto, skip, or show"
            echo "  --step-sshd <mode>        SSH switch step: auto, skip, or show"
            echo "  --step-firewall <mode>    Firewall step: auto, skip, or show"
            echo "  --step-systemd <mode>     Systemd step: auto, skip, or show"
            echo "  --skip-docker             Skip Docker CE installation"
            echo "  --skip-sshd-switch        Do not switch host sshd port from 22"
            echo "  --skip-firewall           Skip UFW firewall rules configuration"
            echo "  --skip-systemd            Skip systemd service registration"
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

# Resolve default install directory and user if empty
CURRENT_LOGIN_USER="$(id -un 2>/dev/null || whoami 2>/dev/null || echo "gickers")"

if [ -z "$SYSTEM_USER" ]; then
    if [ "$IS_ROOT" -eq 1 ]; then
        SYSTEM_USER="gickers"
    else
        SYSTEM_USER="$CURRENT_LOGIN_USER"
    fi
fi

if [ -z "$INSTALL_DIR" ]; then
    if [ -n "$PROJECT_ROOT" ]; then
        INSTALL_DIR="$PROJECT_ROOT"
    elif [ "$SYSTEM_USER" = "root" ]; then
        INSTALL_DIR="/var/www/gickers"
    elif [ -d "/home/$SYSTEM_USER" ]; then
        INSTALL_DIR="/home/$SYSTEM_USER/gickers.sh"
    else
        INSTALL_DIR="/var/lib/gickers"
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

CURRENT_USER=$(whoami 2>/dev/null || echo "user")

# Package Manager and Init System Abstraction
PKG_MGR="unknown"
if command -v apt-get >/dev/null 2>&1; then
    PKG_MGR="apt"
elif command -v apk >/dev/null 2>&1; then
    PKG_MGR="apk"
elif command -v dnf >/dev/null 2>&1; then
    PKG_MGR="dnf"
elif command -v pacman >/dev/null 2>&1; then
    PKG_MGR="pacman"
fi

INIT_SYSTEM="unknown"
if [ -d /run/systemd/system ] || command -v systemctl >/dev/null 2>&1; then
    INIT_SYSTEM="systemd"
elif [ -f /sbin/openrc-run ] || command -v rc-service >/dev/null 2>&1; then
    INIT_SYSTEM="openrc"
fi

# Package Name Resolver per Package Manager
pkg_resolve() {
    local generic_pkg="$1"
    case "$PKG_MGR" in
        apk)
            case "$generic_pkg" in
                docker)          echo "docker docker-cli-compose" ;;
                base-prereqs)    echo "ca-certificates curl gnupg git git-lfs jq ufw" ;;
                system-user-add) echo "shadow" ;;
                *)               echo "$generic_pkg" ;;
            esac
            ;;
        apt)
            case "$generic_pkg" in
                docker)          echo "docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin" ;;
                base-prereqs)    echo "ca-certificates curl gnupg git git-lfs jq ufw" ;;
                *)               echo "$generic_pkg" ;;
            esac
            ;;
        dnf)
            case "$generic_pkg" in
                docker)          echo "docker-ce docker-ce-cli containerd.io docker-compose-plugin" ;;
                base-prereqs)    echo "ca-certificates curl gnupg2 git git-lfs jq" ;;
                *)               echo "$generic_pkg" ;;
            esac
            ;;
        pacman)
            case "$generic_pkg" in
                docker)          echo "docker docker-compose" ;;
                base-prereqs)    echo "ca-certificates curl gnupg git git-lfs jq ufw" ;;
                *)               echo "$generic_pkg" ;;
            esac
            ;;
        *)
            echo "$generic_pkg"
            ;;
    esac
}

pkg_update_cmd() {
    case "$PKG_MGR" in
        apt)    echo "apt-get update -y" ;;
        apk)    echo "apk update" ;;
        dnf)    echo "dnf check-update -y" ;;
        pacman) echo "pacman -Sy" ;;
        *)      echo "true" ;;
    esac
}

pkg_install_cmd() {
    local pkgs=("$@")
    local resolved=()
    for p in "${pkgs[@]}"; do
        resolved+=($(pkg_resolve "$p"))
    done
    case "$PKG_MGR" in
        apt)    echo "apt-get install -y --no-install-recommends ${resolved[*]}" ;;
        apk)    echo "apk add --no-cache ${resolved[*]}" ;;
        dnf)    echo "dnf install -y ${resolved[*]}" ;;
        pacman) echo "pacman -S --noconfirm ${resolved[*]}" ;;
        *)      echo "true" ;;
    esac
}


# ------------------------------------------------------------------------------
# 1. Détection automatique de l'état du serveur
# ------------------------------------------------------------------------------
DOCKER_AVAILABLE=0
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    DOCKER_AVAILABLE=1
fi

SSHD_ON_PORT_22=0
PORT_22_LISTENER=$(ss -tlnp 2>/dev/null | grep -E ':22[[:space:]]' || true)
if [ -n "$PORT_22_LISTENER" ]; then
    if echo "$PORT_22_LISTENER" | grep -E 'sshd|systemd|ssh' >/dev/null 2>&1; then
        SSHD_ON_PORT_22=1
    fi
fi

UFW_ACTIVE=0
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -qw "active"; then
    UFW_ACTIVE=1
fi

SYSTEMD_GICKERS_EXISTS=0
if [ -f /etc/systemd/system/gickers.service ]; then
    SYSTEMD_GICKERS_EXISTS=1
fi

ENV_DOCKER_EXISTS=0
if [ -f "$INSTALL_DIR/.env.docker" ] || [ -f "./.env.docker" ]; then
    ENV_DOCKER_EXISTS=1
fi

# ------------------------------------------------------------------------------
# Routine Dédiée : Intégration Système Hôte & Sudoers (--system-only)
# ------------------------------------------------------------------------------
run_system_only() {
    printf "\n"
    printf "  %s🚀 Gickers.sh — Intégration Système Hôte & Sudoers%s\n" "$C_BOLD$C_CYAN" "$C_RESET"
    printf "  %sConfiguration exclusive de l'infrastructure hôte (ports, Docker, OS) :%s\n" "$C_MUTED" "$C_RESET"
    printf "    %s•%s %sDépendances & Docker CE officiel (si manquant)%s\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$C_RESET"
    printf "    %s•%s %sLibération port 22 pour Git & bascule OpenSSH vers 2222%s\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$C_RESET"
    printf "    %s•%s %sRègles de pare-feu UFW (80, 443, 22, 2222)%s\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$C_RESET"
    printf "    %s•%s %sService systemd /etc/systemd/system/gickers.service au boot%s\n\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$C_RESET"
    printf "  %s✔ Les conteneurs, base de données et données restent inchangés.%s\n\n" "$C_GREEN" "$C_RESET"

    local EXEC_SUDO=""
    if [ "$IS_ROOT" -eq 1 ]; then
        EXEC_SUDO=""
    else
        if command -v sudo >/dev/null 2>&1; then
            local sudo_out
            sudo_out=$(sudo -v 2>&1) && local sudo_res=0 || local sudo_res=$?
            if [ "$sudo_res" -eq 0 ]; then
                EXEC_SUDO="sudo"
            else
                if echo "$sudo_out" | grep -qiE 'not in sudoers|may not run sudo'; then
                    printf "  %s✖%s %sL'utilisateur '%s' n'est pas dans les sudoers sur ce serveur.%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$CURRENT_USER" "$C_RESET"
                else
                    printf "  %s✖%s %sAuthentification sudo échouée.%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$C_RESET"
                fi
                printf "    %sExécutez cette commande avec un compte administrateur :%s\n" "$C_MUTED" "$C_RESET"
                printf "    %scurl -fsSL https://.../install.sh | sudo bash -s -- --system-only%s\n\n" "$C_CYAN" "$C_RESET"
                exit 1
            fi
        else
            printf "  %s✖%s %sPrivilèges root requis (sudo absent).%s\n\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$C_RESET"
            exit 1
        fi
    fi

    # Localisation du répertoire applicatif
    if [ -z "$INSTALL_DIR" ] || [ ! -f "$INSTALL_DIR/compose.yaml" ]; then
        if [ -f "$PWD/compose.yaml" ]; then
            INSTALL_DIR="$PWD"
        elif [ -n "${SUDO_USER:-}" ] && [ -f "/home/$SUDO_USER/gickers.sh/compose.yaml" ]; then
            INSTALL_DIR="/home/$SUDO_USER/gickers.sh"
        elif [ -f "/var/www/gickers/compose.yaml" ]; then
            INSTALL_DIR="/var/www/gickers"
        else
            local candidate
            candidate=$(find /home -maxdepth 3 -name "compose.yaml" 2>/dev/null | grep -i "gickers" | head -n 1 || true)
            if [ -n "$candidate" ]; then
                INSTALL_DIR="$(dirname "$candidate")"
            fi
        fi
    fi

    if [ -z "$INSTALL_DIR" ] || [ ! -f "$INSTALL_DIR/compose.yaml" ]; then
        if [ "$NON_INTERACTIVE" -eq 0 ]; then
            gum_input "Répertoire de l'application gickers.sh existante" INSTALL_DIR "${INSTALL_DIR:-/home/${SUDO_USER:-$CURRENT_USER}/gickers.sh}"
        fi
    fi

    if [ ! -f "$INSTALL_DIR/compose.yaml" ]; then
        printf "  %s✖%s %sFichier compose.yaml introuvable dans '%s'.%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$INSTALL_DIR" "$C_RESET"
        printf "    %sPrécisez le chemin avec : --dir <chemin>%s\n\n" "$C_MUTED" "$C_RESET"
        exit 1
    fi

    printf "  %s✔%s %sApplication détectée dans : ${C_BOLD}${C_GOLD}%s${C_RESET}\n" "$C_GREEN" "$C_RESET" "$C_WHITE" "$INSTALL_DIR"

    # Charger APP_DOMAIN depuis .env.docker
    if [ -f "$INSTALL_DIR/.env.docker" ]; then
        local cfg_domain
        cfg_domain=$(grep -E '^APP_DOMAIN=' "$INSTALL_DIR/.env.docker" | cut -d '=' -f2- | tr -d ' "' || true)
        [ -n "$cfg_domain" ] && DOMAIN="$cfg_domain"
    fi

    local app_owner
    app_owner=$(stat -c '%U' "$INSTALL_DIR" 2>/dev/null || stat -f '%Su' "$INSTALL_DIR" 2>/dev/null || echo "${SUDO_USER:-$CURRENT_USER}")
    [ "$app_owner" = "root" ] && [ -n "${SUDO_USER:-}" ] && app_owner="$SUDO_USER"
    SYSTEM_USER="$app_owner"
    SSH_PORT="22"

    if [ "$NON_INTERACTIVE" -eq 0 ]; then
        gum_confirm "Appliquer la configuration système hôte pour $DOMAIN ($INSTALL_DIR) ?" 1 || {
            printf "\n  %sℹ%s %sOpération annulée.%s\n\n" "$C_CYAN" "$C_RESET" "$C_MUTED" "$C_RESET"
            exit 0
        }
    fi

    if [ "$DRY_RUN" -eq 1 ]; then
        printf "\n  %s✔%s %s[DRY-RUN] Validation réussie. Aucune modification système effectuée.%s\n\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_GREEN" "$C_RESET"
        exit 0
    fi

# 1. Groupe docker
if id -u "$SYSTEM_USER" >/dev/null 2>&1; then
    if [ "$PKG_MGR" = "apk" ]; then
        $EXEC_SUDO addgroup "$SYSTEM_USER" docker 2>/dev/null || true
    else
        $EXEC_SUDO usermod -aG docker "$SYSTEM_USER" 2>/dev/null || true
    fi
fi

# 2. Docker si non installé
if ! command -v docker >/dev/null 2>&1; then
    run_logged_step "Installation officielle de Docker & Compose" "/tmp/gickers_system_docker.log" "$PWD" \
        bash -c "
            case '$PKG_MGR' in
                apk)
                    $EXEC_SUDO apk update
                    $EXEC_SUDO apk add --no-cache ca-certificates curl git git-lfs jq ufw docker docker-cli-compose
                    $EXEC_SUDO rc-update add docker boot || true
                    $EXEC_SUDO service docker start || true
                    ;;
                dnf)
                    $EXEC_SUDO dnf install -y dnf-plugins-core
                    $EXEC_SUDO dnf config-manager --add-repo https://download.docker.com/linux/fedora/docker-ce.repo
                    $EXEC_SUDO dnf install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
                    $EXEC_SUDO systemctl enable --now docker || true
                    ;;
                pacman)
                    $EXEC_SUDO pacman -Sy --noconfirm docker docker-compose git git-lfs curl jq ufw
                    $EXEC_SUDO systemctl enable --now docker || true
                    ;;
                *)
                    if [ -d /etc/apt ]; then
                        $EXEC_SUDO apt-get update -y
                        $EXEC_SUDO apt-get install -y --no-install-recommends ca-certificates curl gnupg git jq ufw
                        $EXEC_SUDO install -m 0755 -d /etc/apt/keyrings
                        curl -fsSL 'https://download.docker.com/linux/$OS_NAME/gpg' | $EXEC_SUDO gpg --dearmor -o /etc/apt/keyrings/docker.gpg --yes
                        $EXEC_SUDO chmod a+r /etc/apt/keyrings/docker.gpg
                        local_coden=\$(. /etc/os-release 2>/dev/null && echo \"\$VERSION_CODENAME\" || echo 'stable')
                        [ -z \"\$local_coden\" ] && local_coden='stable'
                        echo \"deb [arch=\$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/$OS_NAME \$local_coden stable\" \
                            | $EXEC_SUDO tee /etc/apt/sources.list.d/docker.list >/dev/null
                        $EXEC_SUDO apt-get update -y
                        $EXEC_SUDO apt-get install -y --no-install-recommends docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
                        $EXEC_SUDO systemctl enable --now docker || true
                    fi
                    ;;
            esac
        "
else
    printf "  %s✔%s %sDocker : déjà opérationnel sur l'hôte%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
fi

    # 3. Bascule OpenSSH vers 2222
    run_logged_step "Bascule de OpenSSH hôte sur le port 2222 (port 22 libéré)" "/tmp/gickers_system_sshd.log" "$PWD" \
        bash -c "
            if command -v ufw >/dev/null 2>&1; then
                $EXEC_SUDO ufw allow 2222/tcp comment 'OpenSSH System (moved from 22)' || true
            fi
            if [ -d /etc/ssh/sshd_config.d ]; then
                echo 'Port 2222' | $EXEC_SUDO tee /etc/ssh/sshd_config.d/99-gickers-ssh-port.conf >/dev/null
                $EXEC_SUDO chmod 644 /etc/ssh/sshd_config.d/99-gickers-ssh-port.conf
            fi
            if [ -f /etc/ssh/sshd_config ]; then
                $EXEC_SUDO sed -i -E 's/^[#[:space:]]*Port[[:space:]]+22[[:space:]]*$/Port 2222/' /etc/ssh/sshd_config
            fi
            if systemctl is-active --quiet ssh.socket 2>/dev/null; then
                $EXEC_SUDO mkdir -p /etc/systemd/system/ssh.socket.d
                echo -e '[Socket]\nListenStream=\nListenStream=2222' | $EXEC_SUDO tee /etc/systemd/system/ssh.socket.d/listen-2222.conf >/dev/null
                $EXEC_SUDO systemctl daemon-reload
                $EXEC_SUDO systemctl restart ssh.socket || true
            else
                $EXEC_SUDO systemctl restart ssh || $EXEC_SUDO systemctl restart sshd || true
            fi
        "

    # Mise à jour du port dans .env.docker
    if [ -f "$INSTALL_DIR/.env.docker" ]; then
        sed -i -E 's/^SOFT_SERVE_SSH_PORT=.*/SOFT_SERVE_SSH_PORT=22/' "$INSTALL_DIR/.env.docker" 2>/dev/null || true
        sed -i -E "s|^SOFT_SERVE_SSH_URL=.*|SOFT_SERVE_SSH_URL=ssh://${DOMAIN}|" "$INSTALL_DIR/.env.docker" 2>/dev/null || true
    fi

    # 4. Pare-feu UFW
    run_logged_step "Installation et application des règles UFW (80, 443, 22, 2222)" "/tmp/gickers_system_ufw.log" "$PWD" \
        bash -c "
            if ! command -v ufw >/dev/null 2>&1; then
                case '$PKG_MGR' in
                    apk)    $EXEC_SUDO apk add --no-cache ufw ;;
                    dnf)    $EXEC_SUDO dnf install -y ufw ;;
                    pacman) $EXEC_SUDO pacman -S --noconfirm ufw ;;
                    *)      $EXEC_SUDO apt-get update -y && $EXEC_SUDO apt-get install -y --no-install-recommends ufw ;;
                esac
            fi

            if command -v ufw >/dev/null 2>&1; then
                $EXEC_SUDO ufw allow 2222/tcp comment 'Host SSH daemon' || true
                $EXEC_SUDO ufw allow 80/tcp comment 'Gickers HTTP' || true
                $EXEC_SUDO ufw allow 443/tcp comment 'Gickers HTTPS' || true
                $EXEC_SUDO ufw allow 22/tcp comment 'Gickers Git SSH' || true
                $EXEC_SUDO ufw --force enable || true
            fi
        "

    # 5. Service systemd
    local systemd_user_line=""
    if [ "$SYSTEM_USER" != "root" ] && id -u "$SYSTEM_USER" >/dev/null 2>&1; then
        systemd_user_line="User=${SYSTEM_USER}
Group=${SYSTEM_USER}"
    fi

    run_logged_step "Enregistrement et activation de gickers.service" "/tmp/gickers_system_service.log" "$PWD" \
        bash -c "
            echo '[Unit]
Description=Gickers.sh Git Forge & Container Platform
Requires=docker.service
After=docker.service network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
${systemd_user_line}
WorkingDirectory=${INSTALL_DIR}
ExecStart=/usr/bin/docker compose --env-file .env.docker up -d
ExecStop=/usr/bin/docker compose --env-file .env.docker down
ExecReload=/usr/bin/docker compose --env-file .env.docker restart
TimeoutStartSec=300

[Install]
WantedBy=multi-user.target' | $EXEC_SUDO tee /etc/systemd/system/gickers.service >/dev/null

            $EXEC_SUDO systemctl daemon-reload || true
            $EXEC_SUDO systemctl enable gickers.service || true
        "

    # Recharger les conteneurs pour appliquer le port 22
    if command -v docker >/dev/null 2>&1; then
        run_logged_step "Actualisation des conteneurs pour le port 22" "/tmp/gickers_system_containers.log" "$INSTALL_DIR" \
            docker compose --env-file .env.docker up -d
    fi

    # Bannière finale de succès
    printf "\n"
    printf "  %s✔ Gickers.sh — Intégration Système Hôte & Sudoers Terminée%s\n" "$C_BOLD$C_GREEN" "$C_RESET"
    printf "  %sLes privilèges et services hôte sont désormais configurés :%s\n" "$C_MUTED" "$C_RESET"
    printf "    %s•%s %sPort Git SSH     : Port 22 réservé pour Git%s\n" "$C_GREEN" "$C_RESET" "$C_WHITE" "$C_RESET"
    printf "    %s•%s %sOpenSSH Système  : Port 2222 (ssh -p 2222 user@host)%s\n" "$C_GREEN" "$C_RESET" "$C_WHITE" "$C_RESET"
    printf "    %s•%s %sPare-feu UFW     : Ports 80, 443, 22 et 2222 autorisés%s\n" "$C_GREEN" "$C_RESET" "$C_WHITE" "$C_RESET"
    printf "    %s•%s %sService systemd  : /etc/systemd/system/gickers.service au boot%s\n\n" "$C_GREEN" "$C_RESET" "$C_WHITE" "$C_RESET"
    printf "  %sRépertoire applicatif : %s%s\n" "$C_MUTED" "$INSTALL_DIR" "$C_RESET"
    printf "  %s✔ Vos données, base de données et utilisateurs sont restés intacts.%s\n\n" "$C_GREEN" "$C_RESET"
    exit 0
}

# ------------------------------------------------------------------------------
# Routines Dédiées : Gestion d'Instance Détectée (Update/Restart/Rebuild/etc.)
# ------------------------------------------------------------------------------
preserve_dir_owner() {
    local target_dir="$1"
    [ ! -d "$target_dir" ] && return 0
    local owner
    owner=$(stat -c '%U' "$target_dir" 2>/dev/null || stat -f '%Su' "$target_dir" 2>/dev/null || echo "")
    if [ -n "$owner" ] && [ "$owner" != "root" ] && id -u "$owner" >/dev/null 2>&1; then
        if [ "$IS_ROOT" -eq 1 ]; then
            chown -R "$owner":"$owner" "$target_dir" 2>/dev/null || true
        elif [ -n "${SUDO_CMD:-}" ]; then
            $SUDO_CMD chown -R "$owner":"$owner" "$target_dir" 2>/dev/null || true
        fi
    fi
}

manage_instance_update() {
    local dir="$1"
    local log_file="/tmp/gickers_update.log"
    printf "\n  %s🔄 Mise à jour de l'instance gickers.sh : %s%s\n\n" "$C_BOLD$C_CYAN" "$dir" "$C_RESET"

    if [ -d "$dir/.git" ]; then
        run_logged_step "Récupération du dernier code source (git pull)" "$log_file" "$dir" \
            bash -c "git fetch --depth 1 origin main && git reset --hard origin/main || git pull --rebase origin main"
    fi

    if [ -f "$dir/compose.yaml" ] && command -v docker >/dev/null 2>&1; then
        run_logged_step "Construction / Mise à jour des images Docker" "$log_file" "$dir" \
            docker compose --env-file .env.docker build
        run_logged_step "Application des migrations Doctrine" "$log_file" "$dir" \
            docker compose --env-file .env.docker run -T --rm init
        run_logged_step "Redémarrage de la stack applicative" "$log_file" "$dir" \
            docker compose --env-file .env.docker up -d
    elif [ ! -f "$dir/compose.yaml" ] || ! command -v docker >/dev/null 2>&1; then
        if command -v php >/dev/null 2>&1; then
            run_logged_step "Application des migrations Doctrine & vidage du cache" "$log_file" "$dir" \
                bash -c "php bin/console doctrine:migrations:migrate --no-interaction && php bin/console cache:clear"
        fi
        if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet gickers.service 2>/dev/null; then
            run_logged_step "Redémarrage du service systemd" "$log_file" "$dir" \
                systemctl restart gickers.service
        fi
    fi

    printf "  %s✔%s %sInstance mise à jour avec succès.%s\n\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
    preserve_dir_owner "$dir"
    exit 0
}

manage_instance_restart() {
    local dir="$1"
    local log_file="/tmp/gickers_restart.log"
    printf "\n  %s🔁 Redémarrage des services : %s%s\n\n" "$C_BOLD$C_CYAN" "$dir" "$C_RESET"

    if [ -f "$dir/compose.yaml" ] && command -v docker >/dev/null 2>&1; then
        run_logged_step "Redémarrage des conteneurs Docker" "$log_file" "$dir" \
            docker compose --env-file .env.docker restart
    elif command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet gickers.service 2>/dev/null; then
        run_logged_step "Redémarrage du service systemd gickers" "$log_file" "$dir" \
            systemctl restart gickers.service
    fi

    printf "  %s✔%s %sServices redémarrés avec succès.%s\n\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
    exit 0
}

manage_instance_rebuild() {
    local dir="$1"
    local log_file="/tmp/gickers_rebuild.log"
    printf "\n  %s🏗️  Reconstruction complète des conteneurs : %s%s\n\n" "$C_BOLD$C_CYAN" "$dir" "$C_RESET"

    if [ -f "$dir/compose.yaml" ] && command -v docker >/dev/null 2>&1; then
        run_logged_step "Reconstruction des images Docker (--no-cache)" "$log_file" "$dir" \
            docker compose --env-file .env.docker build --no-cache
        run_logged_step "Redémarrage des conteneurs avec les nouvelles images" "$log_file" "$dir" \
            docker compose --env-file .env.docker up -d --force-recreate
    fi

    printf "  %s✔%s %sImages reconstruites et conteneurs relancés avec succès.%s\n\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
    exit 0
}

manage_instance_redeploy() {
    local dir="$1"
    local log_file="/tmp/gickers_redeploy.log"
    printf "\n  %s🚀 Redéploiement (cache, migrations & stack) : %s%s\n\n" "$C_BOLD$C_CYAN" "$dir" "$C_RESET"

    if [ -f "$dir/compose.yaml" ] && command -v docker >/dev/null 2>&1; then
        run_logged_step "Exécution des migrations & warmup cache (init)" "$log_file" "$dir" \
            docker compose --env-file .env.docker run -T --rm init
        run_logged_step "Redéploiement de la stack" "$log_file" "$dir" \
            docker compose --env-file .env.docker up -d
    fi

    printf "  %s✔%s %sRedéploiement terminé avec succès.%s\n\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
    exit 0
}

manage_instance_edit() {
    local dir="$1"
    local env_file="$dir/.env.docker"
    [ ! -f "$env_file" ] && env_file="$dir/.env.local"
    if [ ! -f "$env_file" ]; then
        printf "  %s✖%s %sFichier de configuration introuvable dans %s%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$dir" "$C_RESET"
        exit 1
    fi

    printf "\n  %s⚙️  Édition de la configuration : %s%s\n\n" "$C_BOLD$C_CYAN" "$env_file" "$C_RESET"

    local cur_domain cur_ssh cur_http cur_https cur_signup
    cur_domain=$(grep -E '^APP_DOMAIN=' "$env_file" 2>/dev/null | cut -d '=' -f2- | tr -d ' "' || echo "gits.run")
    cur_ssh=$(grep -E '^SSH_PORT=' "$env_file" 2>/dev/null | cut -d '=' -f2- | tr -d ' "' || echo "22")
    cur_http=$(grep -E '^HTTP_PORT=' "$env_file" 2>/dev/null | cut -d '=' -f2- | tr -d ' "' || echo "80")
    cur_https=$(grep -E '^HTTPS_PORT=' "$env_file" 2>/dev/null | cut -d '=' -f2- | tr -d ' "' || echo "443")
    cur_signup=$(grep -E '^SOFT_SERVE_ALLOW_SIGNUP=' "$env_file" 2>/dev/null | cut -d '=' -f2- | tr -d ' "' || echo "true")

    gum_input "Domaine public (FQDN)" cur_domain "$cur_domain"
    gum_input "Port SSH public" cur_ssh "$cur_ssh"
    gum_input "Port HTTP public" cur_http "$cur_http"
    gum_input "Port HTTPS public" cur_https "$cur_https"

    local SIGN_OPTS=("Inscriptions ouvertes (true)" "Inscriptions fermées (false)")
    gum_choose "Politique d'inscriptions" "${SIGN_OPTS[@]}" && local s_idx=$? || local s_idx=$?
    [ "$s_idx" -eq 1 ] && cur_signup="false" || cur_signup="true"

    local new_ssh_url="ssh://${cur_domain}"
    if [ "$cur_ssh" != "22" ] && [ -n "$cur_ssh" ]; then
        new_ssh_url="ssh://${cur_domain}:${cur_ssh}"
    fi

    sed -i.bak -E \
        -e "s|^APP_DOMAIN=.*|APP_DOMAIN=${cur_domain}|" \
        -e "s|^SSH_PORT=.*|SSH_PORT=${cur_ssh}|" \
        -e "s|^HTTP_PORT=.*|HTTP_PORT=${cur_http}|" \
        -e "s|^HTTPS_PORT=.*|HTTPS_PORT=${cur_https}|" \
        -e "s|^SOFT_SERVE_ALLOW_SIGNUP=.*|SOFT_SERVE_ALLOW_SIGNUP=${cur_signup}|" \
        -e "s|^SOFT_SERVE_SSH_PUBLIC_URL=.*|SOFT_SERVE_SSH_PUBLIC_URL=${new_ssh_url}|" \
        -e "s|^SOFT_SERVE_HTTP_PUBLIC_URL=.*|SOFT_SERVE_HTTP_PUBLIC_URL=https://${cur_domain}|" \
        "$env_file"
    rm -f "${env_file}.bak"

    printf "\n  %s✔%s %sConfiguration mise à jour dans %s%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$env_file" "$C_RESET"

    if [ -f "$dir/compose.yaml" ] && command -v docker >/dev/null 2>&1; then
        run_logged_step "Application de la nouvelle configuration aux conteneurs" "/tmp/gickers_edit_apply.log" "$dir" \
            docker compose --env-file .env.docker up -d
    fi

    printf "  %s✔%s %sModifications appliquées avec succès.%s\n\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
    preserve_dir_owner "$dir"
    exit 0
}

manage_instance_delete() {
    local dir="$1"
    local dom="$2"
    [ -z "$dom" ] && dom="cette instance"
    local expected_confirm="DELETE $dom"

    printf "\n"
    printf "  %s⚠️  ATTENTION : DEMANDE DE SUPPRESSION DÉFINITIVE (DELETE)%s\n" "$C_BOLD$C_PINK" "$C_RESET"
    printf "  %sCible : %s%s%s (Répertoire : %s%s%s)%s\n" "$C_MUTED" "$C_BOLD$C_WHITE" "$dom" "$C_RESET" "$C_CYAN" "$dir" "$C_RESET" "$C_RESET"
    printf "  %sCette action est %sIRRÉVERSIBLE%s%s et va détruire définitivement :%s\n" "$C_WHITE" "$C_BOLD$C_PINK" "$C_RESET" "$C_WHITE" "$C_RESET"
    printf "    %s•%s %sTous les conteneurs, réseaux et volumes Docker de cette instance%s\n" "$C_PINK" "$C_RESET" "$C_MUTED" "$C_RESET"
    printf "    %s•%s %sLa base de données PostgreSQL, les dépôts Git et configurations%s\n" "$C_PINK" "$C_RESET" "$C_MUTED" "$C_RESET"
    printf "    %s•%s %sLe dossier d'installation %s sur le serveur%s\n\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_WHITE" "$dir" "$C_RESET"

    local CONFIRM_OPTS=(
        "Non, annuler et conserver mon instance intacte (Recommandé)"
        "Oui, je veux détruire et supprimer définitivement cette instance"
    )
    gum_choose "Confirmation 1/2 : Êtes-vous certain de vouloir supprimer cette instance ?" "${CONFIRM_OPTS[@]}" && local c1=$? || local c1=$?
    if [ "$c1" -ne 1 ]; then
        printf "  %s✔%s %sSuppression annulée. L'instance reste intacte.%s\n\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
        exit 0
    fi

    local del_confirm=""
    gum_input "Confirmation 2/2 : Tapez '$expected_confirm' pour confirmer" del_confirm "" "$expected_confirm"
    if [ "$del_confirm" != "$expected_confirm" ]; then
        printf "  %s✔%s %sSaisie non conforme ('%s' != '%s'). Suppression annulée, données préservées.%s\n\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$del_confirm" "$expected_confirm" "$C_RESET"
        exit 0
    fi

    printf "\n  %s🗑️  Suppression définitive en cours pour %s...%s\n" "$C_BOLD$C_PINK" "$dom" "$C_RESET"

    if [ -d "$dir" ] && command -v docker >/dev/null 2>&1; then
        run_logged_step "Arrêt et destruction des conteneurs et volumes Docker" "/tmp/gickers_delete_docker.log" "$dir" \
            bash -c "docker compose --env-file .env.docker down -v --remove-orphans || docker compose down -v --remove-orphans || true"
    fi

    if command -v docker >/dev/null 2>&1; then
        docker volume rm -f gickers-sh_gickers-data gickers-sh_gickers-db gickers-sh_gickers-var gickers-sh_gickers-deploy-engine >/dev/null 2>&1 || true
    fi

    if [ -d "$dir" ] && [ "$dir" != "/" ] && [ "$dir" != "$HOME" ]; then
        run_logged_step "Suppression du répertoire $dir" "/tmp/gickers_delete_fs.log" "$PWD" \
            rm -rf "$dir"
    fi

    local sudo_bin=""
    [ "$IS_ROOT" -eq 0 ] && [ "$USE_SUDO" = "1" ] && sudo_bin="sudo"
    if [ "$IS_ROOT" -eq 1 ] || [ -n "$sudo_bin" ]; then
        if [ -f /etc/systemd/system/gickers.service ] && grep -qxF "WorkingDirectory=${dir}" /etc/systemd/system/gickers.service 2>/dev/null; then
            $sudo_bin systemctl disable --now gickers.service >/dev/null 2>&1 || true
            $sudo_bin rm -f /etc/systemd/system/gickers.service 2>/dev/null || true
            $sudo_bin systemctl daemon-reload >/dev/null 2>&1 || true
        fi
    fi

    printf "  %s✔%s %sInstance %s supprimée définitivement avec succès. Ports et ressources libérés.%s\n\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$dom" "$C_RESET"
    exit 0
}

# ------------------------------------------------------------------------------
# 2. Gestion et Avertissements Privilèges (Sudo vs Non-Sudo)
# ------------------------------------------------------------------------------
if [ "$SYSTEM_ONLY" -eq 1 ]; then
    run_system_only
fi

if [ "$IS_ROOT" -eq 1 ]; then
    printf "  %s✔%s %sPrivilèges : ${C_BOLD}${C_GREEN}root${C_RESET} %s(%s, accès complet au système hôte)%s\n" "$C_GREEN" "$C_RESET" "$C_WHITE" "$C_MUTED" "$OS_PRETTY" "$C_RESET"
else
    CAN_SUDO=0
    if command -v sudo >/dev/null 2>&1; then
        CAN_SUDO=1
    fi

    if [ "$USE_SUDO" = "1" ] && [ "$CAN_SUDO" -eq 1 ]; then
        sudo_out=$(sudo -v 2>&1) && sudo_res=0 || sudo_res=$?
        if [ "$sudo_res" -eq 0 ]; then
            SUDO_CMD="sudo"
            printf "  %s✔%s %sPrivilèges : ${C_BOLD}${C_GREEN}%s (mode sudo activé)${C_RESET} %s(%s)%s\n" "$C_GREEN" "$C_RESET" "$C_WHITE" "$CURRENT_USER" "$C_MUTED" "$OS_PRETTY" "$C_RESET"
        else
            if echo "$sudo_out" | grep -qiE 'not in sudoers|may not run sudo'; then
                printf "  %s✖%s %sL'utilisateur '%s' n'est pas sudoer sur ce serveur.%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$CURRENT_USER" "$C_RESET"
            else
                printf "  %s!%s %sÉchec de l'authentification sudo. Poursuite en mode applicatif utilisateur.%s\n" "$C_GOLD" "$C_RESET" "$C_MUTED" "$C_RESET"
            fi
            USE_SUDO="0"
            SUDO_CMD=""
        fi
    fi

    if [ "$NON_INTERACTIVE" -eq 0 ] && [ "$USE_SUDO" != "1" ]; then
        printf "\n"
        printf "  %sℹ%s %sUtilisateur non-root détecté : ${C_BOLD}${C_GOLD}%s${C_RESET}\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$CURRENT_USER"
        printf "    %s• Mode Sudo : permet d'automatiser la configuration de l'OS (Docker CE, pare-feu UFW, bascule SSH 22, systemd).%s\n" "$C_MUTED" "$C_RESET"
        printf "    %s• Mode Applicatif : installe l'application dans votre home sans toucher aux fichiers système de l'hôte.%s\n\n" "$C_MUTED" "$C_RESET"

        SUDO_OPTIONS=()
        if [ "$CAN_SUDO" -eq 1 ]; then
            SUDO_OPTIONS+=("Activer le mode sudo (Recommandé : Docker, UFW, SSH 22, systemd)")
        fi
        SUDO_OPTIONS+=(
            "Continuer en mode applicatif utilisateur (Docker sans sudo)"
            "Afficher les conseils d'usage et les impacts de sécurité"
        )

        SHOW_EXPLANATION=1
        while [ "$SHOW_EXPLANATION" -eq 1 ]; do
            gum_choose "Gestion des privilèges d'installation" "${SUDO_OPTIONS[@]}" && SUDO_CHOICE=$? || SUDO_CHOICE=$?
            if [ "$CAN_SUDO" -eq 1 ]; then
                case "$SUDO_CHOICE" in
                    0)
                        sudo_out=$(sudo -v 2>&1) && sudo_res=0 || sudo_res=$?
                        if [ "$sudo_res" -eq 0 ]; then
                            SUDO_CMD="sudo"
                            USE_SUDO="1"
                            SHOW_EXPLANATION=0
                            printf "  %s✔%s %sMode sudo activé avec succès.%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_GREEN" "$C_RESET"
                        else
                            if echo "$sudo_out" | grep -qiE 'not in sudoers|may not run sudo'; then
                                printf "  %s✖%s %sL'utilisateur '%s' n'a pas les droits sudo sur ce serveur (non-sudoer).%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$CURRENT_USER" "$C_RESET"
                                printf "    %sLes étapes d'administration système hôte seront ignorées pour cette session.%s\n" "$C_MUTED" "$C_RESET"
                            else
                                printf "  %s✖%s %sMot de passe sudo invalide ou annulé. Poursuite en mode applicatif.%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$C_RESET"
                            fi
                            USE_SUDO="0"
                            SUDO_CMD=""
                            SHOW_EXPLANATION=0
                        fi
                        ;;
                    1)
                        SHOW_EXPLANATION=0
                        SUDO_CMD=""
                        ;;
                    2)
                        printf "\n"
                        printf "  %sImpacts et conseils d'usage :%s\n" "$C_BOLD$C_CYAN" "$C_RESET"
                        printf "  1. ${C_BOLD}Port 22 vs 2222 :${C_RESET} Réserver le port 22 pour gickers.sh permet des commandes Git standard (${C_GREEN}git clone ssh://domain/repo.git${C_RESET}). Nécessite sudo pour déplacer OpenSSH hôte sur le port 2222.\n"
                        printf "  2. ${C_BOLD}Docker CE :${C_RESET} Si Docker n'est pas installé, sudo est nécessaire pour installer les dépôts officiels Docker CE.\n"
                        printf "  3. ${C_BOLD}Pare-feu UFW :${C_RESET} Sudo est requis pour ouvrir les ports 80, 443 et SSH.\n"
                        printf "  4. ${C_BOLD}Systemd :${C_RESET} Sudo est requis pour enregistrer gickers.service et assurer le redémarrage automatique au boot du serveur.\n"
                        printf "  5. ${C_BOLD}Sans sudo :${C_RESET} Vous pouvez exécuter gickers.sh sur un port non privilégié (ex: 2223) si votre utilisateur est dans le groupe docker.\n\n"
                        ;;
                esac
            else
                case "$SUDO_CHOICE" in
                    0)
                        SHOW_EXPLANATION=0
                        SUDO_CMD=""
                        ;;
                    1)
                        printf "\n  %s(Aucun binaire sudo détecté sur cette machine. Exécution en mode applicatif pur.)%s\n\n" "$C_MUTED" "$C_RESET"
                        SHOW_EXPLANATION=0
                        ;;
                esac
            fi
        done
    fi
fi

# Si l'utilisateur est non-root et non-sudo, avertir sur les étapes ignorées
if [ "$IS_ROOT" -eq 0 ] && [ "$USE_SUDO" != "1" ]; then
    printf "\n"
    printf "  %sℹ%s %sMode Applicatif Utilisateur — Privilèges hôte non activés%s\n" "$C_CYAN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
    printf "  %sSans privilèges root/sudo, les étapes système suivantes sont ignorées :%s\n" "$C_MUTED" "$C_RESET"
    printf "    %s•%s %sInstallation de Docker CE hôte (apt-get)%s\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$C_RESET"
    printf "    %s•%s %sDéplacement OpenSSH et réservation du port 22 pour Git%s\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$C_RESET"
    printf "    %s•%s %sConfiguration du pare-feu UFW de l'hôte%s\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$C_RESET"
    printf "    %s•%s %sService systemd /etc/systemd/system/gickers.service au boot%s\n\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$C_RESET"
    printf "  %s➜ Pour exécuter uniquement ces étapes plus tard en root ou sudo :%s\n" "$C_GOLD" "$C_RESET"
    printf "    %scurl -fsSL https://raw.githubusercontent.com/gickerssh/gickers.sh/main/scripts/install.sh | sudo bash -s -- --system-only%s\n\n" "$C_CYAN" "$C_RESET"
fi

# ==============================================================================
# INVENTAIRE EN AMONT DES INSTANCES GICKERS.SH & CHOIX D'ACTION
# ==============================================================================
EXISTING_INSTALL=0
EXISTING_DETAIL=""
EXISTING_DOMAIN=""
CREATE_NEW_INSTANCE=0

# 1. Recherche et inventaire de toutes les instances applicatives sur la machine
DETECTED_DIRS=()
CANDIDATE_SEARCH_PATHS=("$INSTALL_DIR" "$PWD" "/home/gickers/gickers.sh" "/var/www/gickers" "$HOME/gickers.sh")

# Détection Docker Compose via les labels de conteneurs et les images officielles
if command -v docker >/dev/null 2>&1; then
    while IFS= read -r w_dir; do
        [ -n "$w_dir" ] && [ -d "$w_dir" ] && CANDIDATE_SEARCH_PATHS+=("$w_dir")
    done < <(docker ps -a --filter "ancestor=gickers-sh:latest" --format '{{ index .Labels "com.docker.compose.project.working_dir" }}' 2>/dev/null | grep -v '^$' || true)

    while IFS= read -r w_dir; do
        [ -n "$w_dir" ] && [ -d "$w_dir" ] && CANDIDATE_SEARCH_PATHS+=("$w_dir")
    done < <(docker ps -a --filter "label=com.docker.compose.project=gickers-sh" --format '{{ index .Labels "com.docker.compose.project.working_dir" }}' 2>/dev/null | grep -v '^$' || true)
fi

for c_dir in "${CANDIDATE_SEARCH_PATHS[@]}"; do
    [ -z "$c_dir" ] || [ ! -d "$c_dir" ] && continue
    if [ -f "$c_dir/compose.yaml" ] || [ -f "$c_dir/docker-compose.yml" ] || [ -f "$c_dir/.env.docker" ] || [ -f "$c_dir/.env.local" ]; then
        already_seen=0
        for seen in "${DETECTED_DIRS[@]}"; do
            [ "$seen" = "$c_dir" ] && already_seen=1 && break
        done
        [ "$already_seen" -eq 0 ] && DETECTED_DIRS+=("$c_dir")
    fi
done

# Collecte des métadonnées et état d'exécution (Runtime State) pour chaque instance
DETECTED_DOMAINS=()
DETECTED_LABELS=()
for d_path in "${DETECTED_DIRS[@]}"; do
    inst_dom=""
    if [ -f "$d_path/.env.docker" ]; then
        inst_dom=$(grep -E '^APP_DOMAIN=' "$d_path/.env.docker" 2>/dev/null | cut -d '=' -f2- | tr -d ' "' || true)
    elif [ -f "$d_path/.env.local" ]; then
        inst_dom=$(grep -E '^APP_DOMAIN=' "$d_path/.env.local" 2>/dev/null | cut -d '=' -f2- | tr -d ' "' || true)
    fi
    [ -z "$inst_dom" ] && inst_dom="domaine non spécifié"
    DETECTED_DOMAINS+=("$inst_dom")

    inst_state="Arrêté"
    if command -v docker >/dev/null 2>&1; then
        running_cnt=$(docker ps --filter "label=com.docker.compose.project.working_dir=$d_path" -q 2>/dev/null | wc -l | tr -d ' ')
        total_cnt=$(docker ps -a --filter "label=com.docker.compose.project.working_dir=$d_path" -q 2>/dev/null | wc -l | tr -d ' ')
        if [ "$total_cnt" -gt 0 ]; then
            if [ "$running_cnt" -gt 0 ]; then
                inst_state="Docker actif (${running_cnt}/${total_cnt} UP)"
            else
                inst_state="Docker arrêté (0/${total_cnt})"
            fi
        fi
    fi

    inst_ssh="22"
    if [ -f "$d_path/.env.docker" ]; then
        inst_ssh=$(grep -E '^SSH_PORT=' "$d_path/.env.docker" 2>/dev/null | cut -d '=' -f2- | tr -d ' "' || echo "22")
    fi

    DETECTED_LABELS+=("$d_path • $inst_dom [$inst_state] (SSH: $inst_ssh)")
done

# Affichage du menu amont si une ou plusieurs instances sont trouvées
if [ "${#DETECTED_DIRS[@]}" -gt 0 ] && [ "$NON_INTERACTIVE" -eq 0 ]; then
    TARGET_INDEX=0
    if [ "${#DETECTED_DIRS[@]}" -gt 1 ]; then
        INST_PICKER_OPTS=()
        for (( i=0; i<${#DETECTED_DIRS[@]}; i++ )); do
            if [ "$i" -eq 0 ]; then
                INST_PICKER_OPTS+=("Gérer l'instance : ${DETECTED_LABELS[$i]} [Recommandé]")
            else
                INST_PICKER_OPTS+=("Gérer l'instance : ${DETECTED_LABELS[$i]}")
            fi
        done
        INST_PICKER_OPTS+=("Créer et déployer une NOUVELLE instance gickers.sh indépendante")

        gum_choose "Instances gickers.sh détectées sur cette machine" "${INST_PICKER_OPTS[@]}" && TARGET_INDEX=$? || TARGET_INDEX=$?
        if [ "$TARGET_INDEX" -eq 255 ]; then
            printf "\n  %sOpération annulée.%s\n\n" "$C_MUTED" "$C_RESET"
            exit 0
        fi

        if [ "$TARGET_INDEX" -ge "${#DETECTED_DIRS[@]}" ]; then
            EXISTING_INSTALL=0
            CREATE_NEW_INSTANCE=1
            INSTALL_DIR=""
        else
            EXISTING_INSTALL=1
            INSTALL_DIR="${DETECTED_DIRS[$TARGET_INDEX]}"
            EXISTING_DOMAIN="${DETECTED_DOMAINS[$TARGET_INDEX]}"
            DOMAIN="$EXISTING_DOMAIN"
        fi
    else
        EXISTING_INSTALL=1
        INSTALL_DIR="${DETECTED_DIRS[0]}"
        EXISTING_DOMAIN="${DETECTED_DOMAINS[0]}"
        DOMAIN="$EXISTING_DOMAIN"
    fi

    if [ "$EXISTING_INSTALL" -eq 1 ]; then
        ACTION_OPTS=(
            "Mettre à jour l'application (Update) [Recommandé]"
            "Redémarrer les services (Restart)"
            "Reconstruire les conteneurs (Rebuild)"
            "Redéployer / Réappliquer migrations & cache (Redeploy)"
            "Éditer la configuration (.env.docker / domaine / ports)"
        )
        if [ "$IS_ROOT" -eq 1 ] || [ "$USE_SUDO" = "1" ]; then
            ACTION_OPTS+=("Mettre à jour uniquement l'infrastructure hôte (Port 22 SSH, UFW, Systemd)")
        fi
        ACTION_OPTS+=(
            "Réinitialisation complète / Hard Reset (⚠️  Supprime base de données et dépôts)"
            "Supprimer définitivement cette instance / Delete (⚠️  Détruit conteneurs, volumes et dossier)"
            "Créer et déployer une NOUVELLE instance gickers.sh indépendante"
        )

        target_title="Gestion de l'instance : $EXISTING_DOMAIN ($INSTALL_DIR)"
        gum_choose "$target_title" "${ACTION_OPTS[@]}" && ACTION_SEL=$? || ACTION_SEL=$?
        if [ "$ACTION_SEL" -eq 255 ]; then
            printf "\n  %sOpération annulée.%s\n\n" "$C_MUTED" "$C_RESET"
            exit 0
        fi

        SELECTED_ACTION="${ACTION_OPTS[$ACTION_SEL]}"

        case "$SELECTED_ACTION" in
            *"Update"*)
                manage_instance_update "$INSTALL_DIR"
                ;;
            *"Restart"*)
                manage_instance_restart "$INSTALL_DIR"
                ;;
            *"Rebuild"*)
                manage_instance_rebuild "$INSTALL_DIR"
                ;;
            *"Redeploy"*)
                manage_instance_redeploy "$INSTALL_DIR"
                ;;
            *"Éditer"*)
                manage_instance_edit "$INSTALL_DIR"
                ;;
            *"infrastructure hôte"*)
                run_system_only
                ;;
            *"Hard Reset"*)
                HARD_RESET=1
                ;;
            *"Delete"*)
                manage_instance_delete "$INSTALL_DIR" "$EXISTING_DOMAIN"
                ;;
            *"NOUVELLE instance"*)
                EXISTING_INSTALL=0
                CREATE_NEW_INSTANCE=1
                INSTALL_DIR=""
                EXISTING_DOMAIN=""
                ;;
        esac
    fi
fi

# Double confirmation stricte pour le Hard Reset (interactif)
if [ "$HARD_RESET" -eq 1 ] && [ "$NON_INTERACTIVE" -eq 0 ]; then
    target_reset_domain="${EXISTING_DOMAIN:-$DOMAIN}"
    expected_confirm_phrase="HARD RESET $target_reset_domain"

    printf "\n"
    printf "  %s⚠️  ATTENTION : DEMANDE DE RÉINITIALISATION COMPLÈTE (HARD RESET)%s\n" "$C_BOLD$C_PINK" "$C_RESET"
    printf "  %sCible : %s%s%s (Répertoire : %s%s%s)%s\n" "$C_MUTED" "$C_BOLD$C_WHITE" "$target_reset_domain" "$C_RESET" "$C_CYAN" "$INSTALL_DIR" "$C_RESET" "$C_RESET"
    printf "  %sCette action est %sIRRÉVERSIBLE%s%s et va détruire définitivement :%s\n" "$C_WHITE" "$C_BOLD$C_PINK" "$C_RESET" "$C_WHITE" "$C_RESET"
    printf "    %s•%s %sTous les dépôts Git hébergés et objets Git LFS de cette instance%s\n" "$C_PINK" "$C_RESET" "$C_MUTED" "$C_RESET"
    printf "    %s•%s %sLa base PostgreSQL (utilisateurs, comptes, clés SSH, tickets)%s\n" "$C_PINK" "$C_RESET" "$C_MUTED" "$C_RESET"
    printf "    %s•%s %sLes conteneurs et volumes Docker associés à cette instance%s\n" "$C_PINK" "$C_RESET" "$C_MUTED" "$C_RESET"
    printf "    %s•%s %sLa configuration de production .env.docker actuelle%s\n\n" "$C_PINK" "$C_RESET" "$C_MUTED" "$C_RESET"

    CONFIRM_OPTS=(
        "Non, annuler et conserver mes données intactes (Recommandé)"
        "Oui, je comprends les risques et souhaite réinitialiser"
    )
    gum_choose "Confirmation 1/2 : Êtes-vous sûr de vouloir supprimer TOUTES les données de $target_reset_domain ?" "${CONFIRM_OPTS[@]}" && CONFIRM_1=$? || CONFIRM_1=$?
    if [ "$CONFIRM_1" -ne 1 ]; then
        printf "  %s✔%s %sRéinitialisation annulée. Vos données sont préservées intactes.%s\n\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
        HARD_RESET=0
        exit 0
    else
        reset_confirm=""
        gum_input "Confirmation 2/2 : Tapez '$expected_confirm_phrase' pour confirmer" reset_confirm "" "$expected_confirm_phrase"
        if [ "$reset_confirm" = "$expected_confirm_phrase" ]; then
            HARD_RESET=1
            printf "  %s✔%s %sDouble confirmation validée. Hard reset enclenché pour %s.%s\n\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_WHITE" "$target_reset_domain" "$C_RESET"
        else
            printf "  %s✔%s %sSaisie non conforme ('%s' != '%s'). Réinitialisation annulée, données préservées.%s\n\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$reset_confirm" "$expected_confirm_phrase" "$C_RESET"
            HARD_RESET=0
            exit 0
        fi
    fi
fi

# Choix interactif du mode de déploiement (Docker vs Natif Host) pour nouvelle installation ou Hard Reset
if [ "$NON_INTERACTIVE" -eq 0 ] && [ "$EXISTING_INSTALL" -eq 0 -o "$HARD_RESET" -eq 1 ]; then
    MODE_OPTS=(
        "Production VPS — Docker & Conteneurs Isolés (Let's Encrypt SSL, Postgres, gVisor) [Recommandé]"
        "Instance Légère — Natif Host / Sans Docker (PHP + SQLite + gickers-ssh autonome) [Idéal dev / machine perso]"
    )
    gum_choose "Mode d'exécution de gickers.sh sur cette machine" "${MODE_OPTS[@]}" && MODE_CHOICE=$? || MODE_CHOICE=$?
    if [ "$MODE_CHOICE" -eq 1 ]; then
        MODE="native"
        STEP_DOCKER="skip"
        if [ "$SSH_PORT" = "22" ]; then
            SSH_PORT="2223"
        fi
        if [ "$HTTP_PORT" = "80" ]; then
            HTTP_PORT="23232"
        fi
    else
        MODE="docker"
    fi
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
# 3. Formulaire Interactif et Sélection Granulaire par Étape
# ------------------------------------------------------------------------------
if [ "$NON_INTERACTIVE" -eq 0 ]; then
    # Préparation des descriptions et commandes pour chaque étape du checklist
    DESC_DOCKER="Mise à jour des dépôts, installation des outils de base (curl, git, jq), configuration du dépôt officiel et installation du moteur Docker avec Docker Compose."
    case "$PKG_MGR" in
        apk)
            CMDS_DOCKER=$(cat << 'EOF'
apk update && \
  apk add --no-cache ca-certificates curl git git-lfs jq ufw docker docker-cli-compose
rc-update add docker boot
service docker start
addgroup $USER docker
EOF
)
            ;;
        dnf)
            CMDS_DOCKER=$(cat << 'EOF'
dnf install -y dnf-plugins-core
dnf config-manager --add-repo https://download.docker.com/linux/fedora/docker-ce.repo
dnf install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin git git-lfs jq curl
systemctl enable --now docker
usermod -aG docker $USER
EOF
)
            ;;
        pacman)
            CMDS_DOCKER=$(cat << 'EOF'
pacman -Sy --noconfirm docker docker-compose git git-lfs curl jq ufw
systemctl enable --now docker
usermod -aG docker $USER
EOF
)
            ;;
        *)
            CMDS_DOCKER=$(cat << 'EOF'
apt-get update -y && \
  apt-get install -y --no-install-recommends ca-certificates curl gnupg git git-lfs jq ufw
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  > /etc/apt/sources.list.d/docker.list
apt-get update -y && \
  apt-get install -y --no-install-recommends docker-ce docker-ce-cli containerd.io docker-compose-plugin
systemctl enable --now docker
usermod -aG docker $USER
EOF
)
            ;;
    esac

    DESC_SSHD="Déplacement du port OpenSSH hôte sur 2222 et libération du port standard 22 pour le serveur Git SSH de ${APP_NAME} (clones et push transparents sans port custom)."
    if [ "$INIT_SYSTEM" = "openrc" ]; then
        CMDS_SSHD=$(cat << 'EOF'
ufw allow 2222/tcp comment 'OpenSSH System (moved from 22)'
echo "Port 2222" > /etc/ssh/sshd_config.d/99-gickers-ssh-port.conf
rc-service sshd restart
EOF
)
    else
        CMDS_SSHD=$(cat << 'EOF'
ufw allow 2222/tcp comment 'OpenSSH System (moved from 22)'
echo "Port 2222" > /etc/ssh/sshd_config.d/99-gickers-ssh-port.conf
systemctl daemon-reload && systemctl restart ssh.socket || systemctl restart sshd
EOF
)
    fi

    DESC_UFW="Installation du pare-feu UFW et configuration des règles pour n'exposer que les flux nécessaires : HTTP/ACME (80), HTTPS (443), Git SSH (22) et SSH système (2222)."
    case "$PKG_MGR" in
        apk)
            CMDS_UFW=$(cat << 'EOF'
apk add --no-cache ufw
ufw allow 80/tcp comment 'Gickers HTTP / ACME'
ufw allow 443/tcp comment 'Gickers HTTPS'
ufw allow 22/tcp comment 'Gickers Git SSH'
ufw allow 2222/tcp comment 'System OpenSSH'
ufw --force enable
EOF
)
            ;;
        dnf)
            CMDS_UFW=$(cat << 'EOF'
dnf install -y ufw
ufw allow 80/tcp comment 'Gickers HTTP / ACME'
ufw allow 443/tcp comment 'Gickers HTTPS'
ufw allow 22/tcp comment 'Gickers Git SSH'
ufw allow 2222/tcp comment 'System OpenSSH'
ufw --force enable
EOF
)
            ;;
        *)
            CMDS_UFW=$(cat << 'EOF'
apt-get install -y --no-install-recommends ufw
ufw allow 80/tcp comment 'Gickers HTTP / ACME'
ufw allow 443/tcp comment 'Gickers HTTPS'
ufw allow 22/tcp comment 'Gickers Git SSH'
ufw allow 2222/tcp comment 'System OpenSSH'
ufw --force enable
EOF
)
            ;;
    esac

    DESC_APP="Clonage du projet ${APP_NAME}, génération de l'environnement sécurisé (.env.docker), build des conteneurs isolés, initialisation Doctrine et démarrage des services."
    CMDS_APP=$(cat << 'EOF'
git clone --depth 1 https://github.com/gickerssh/gickers.sh.git "$INSTALL_DIR"
cd "$INSTALL_DIR"
docker compose --env-file .env.docker build
docker compose --env-file .env.docker up -d postgres
docker compose --env-file .env.docker run --rm init
docker compose --env-file .env.docker up -d
EOF
)

    DESC_SYSTEMD="Configuration du gestionnaire de services système de l'hôte pour garantir le démarrage automatique et la persistance des conteneurs à chaque reboot du serveur."
    if [ "$INIT_SYSTEM" = "openrc" ]; then
        CMDS_SYSTEMD=$(cat << 'EOF'
# Création du service OpenRC /etc/init.d/gickers
rc-update add gickers default
rc-service gickers start
EOF
)
    else
        CMDS_SYSTEMD=$(cat << 'EOF'
tee /etc/systemd/system/gickers.service << 'UNIT'
[Unit]
Description=Gickers.sh Git Forge & Container Platform
Requires=docker.service
After=docker.service network-online.target
[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=/home/gickers/gickers.sh
ExecStart=/usr/bin/docker compose --env-file .env.docker up -d
ExecStop=/usr/bin/docker compose --env-file .env.docker down
[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload && systemctl enable gickers.service
EOF
)
    fi

    if [ "$MODE" = "native" ]; then
        DESC_NATIVE="Installation des outils de développement hôte (PHP >= 8.2, Composer, Go >= 1.22, Git), compilation de bin/gickers-ssh et migration SQLite."
        CMDS_NATIVE=$(cat << 'EOF'
$(pkg_install_cmd php php-cli php-mbstring php-xml php-sqlite3 composer git golang)
make setup && make build
./bin/soft doctrine:migrations:migrate --allow-no-migration --no-interaction
EOF
)
        DESC_NATIVE_SYSTEMD="Service systemd gickers.service pour lancer ./bin/soft serve automatiquement au boot."
        CMDS_NATIVE_SYSTEMD=$(cat << 'EOF'
tee /etc/systemd/system/gickers.service << 'UNIT'
[Unit]
Description=Gickers.sh Native Git Forge & Platform
After=network.target
[Service]
Type=simple
User=$SYSTEM_USER
WorkingDirectory=$INSTALL_DIR
ExecStart=$INSTALL_DIR/bin/soft serve
Restart=always
[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload && systemctl enable --now gickers.service
EOF
)
        CHECKLIST_ITEMS=(
            "Outils de base & Compilation native|1|${DESC_NATIVE}|${CMDS_NATIVE}"
            "Pare-feu hôte UFW (Installation & règles)|0|${DESC_UFW}|${CMDS_UFW}"
            "Déploiement applicatif (Sources & Dépendances)|1|${DESC_NATIVE}|${CMDS_NATIVE}"
            "Service système au démarrage (Boot VPS)|0|${DESC_NATIVE_SYSTEMD}|${CMDS_NATIVE_SYSTEMD}"
        )
        gum_checklist "Sélectionnez les étapes à exécuter sur cette machine" SELECTED_STEPS "${CHECKLIST_ITEMS[@]}"

        STEP_DOCKER="skip"
        STEP_SSHD="skip"
        if [ "${SELECTED_STEPS[1]:-0}" -eq 0 ]; then
            STEP_FIREWALL="skip"
        fi
        if [ "${SELECTED_STEPS[2]:-1}" -eq 0 ]; then
            SKIP_APP_DEPLOY=1
        else
            SKIP_APP_DEPLOY=0
        fi
        if [ "${SELECTED_STEPS[3]:-0}" -eq 0 ]; then
            STEP_SYSTEMD="skip"
        fi
    else
        # Sélection des étapes à exécuter au lancement (mode Docker)
        CHECKLIST_ITEMS=(
            "Dépendances système & Docker CE|1|${DESC_DOCKER}|${CMDS_DOCKER}"
            "Port SSH Git 22 (Bascule OpenSSH vers 2222)|1|${DESC_SSHD}|${CMDS_SSHD}"
            "Pare-feu hôte UFW (Installation & règles)|1|${DESC_UFW}|${CMDS_UFW}"
            "Déploiement applicatif (Conteneurs Docker)|1|${DESC_APP}|${CMDS_APP}"
            "Service système au démarrage (Boot VPS)|1|${DESC_SYSTEMD}|${CMDS_SYSTEMD}"
        )
        gum_checklist "Sélectionnez les étapes à exécuter sur cette machine" SELECTED_STEPS "${CHECKLIST_ITEMS[@]}"

        if [ "${SELECTED_STEPS[0]:-1}" -eq 0 ]; then
            STEP_DOCKER="skip"
        fi
        if [ "${SELECTED_STEPS[1]:-1}" -eq 0 ]; then
            STEP_SSHD="skip"
        fi
        if [ "${SELECTED_STEPS[2]:-1}" -eq 0 ]; then
            STEP_FIREWALL="skip"
        fi
        if [ "${SELECTED_STEPS[3]:-1}" -eq 0 ]; then
            SKIP_APP_DEPLOY=1
        else
            SKIP_APP_DEPLOY=0
        fi
        if [ "${SELECTED_STEPS[4]:-1}" -eq 0 ]; then
            STEP_SYSTEMD="skip"
        fi
    fi

    # --------------------------------------------------------------------------
    # ÉTAPE SYSTÈME 1 : Docker & Dépendances
    # --------------------------------------------------------------------------
    if [ "$STEP_DOCKER" = "skip" ]; then
        printf "  %sℹ%s %sDépendances système & Docker CE : ignoré (décoché par l'utilisateur)%s\n" "$C_CYAN" "$C_RESET" "$C_MUTED" "$C_RESET"
    elif [ "$DOCKER_AVAILABLE" -eq 1 ]; then
        DOCKER_VER=$(docker --version 2>/dev/null | awk '{print $3}' | tr -d ',' || echo "ok")
        printf "  %s✔%s %sDocker est déjà accessible pour %s : ${C_BOLD}${C_GREEN}Docker %s & Compose${C_RESET}\n" "$C_GREEN" "$C_RESET" "$C_WHITE" "$CURRENT_USER" "$DOCKER_VER"
        STEP_DOCKER="skip"
    elif [ "$IS_ROOT" -eq 0 ] && [ "$USE_SUDO" != "1" ]; then
        printf "\n  %s!%s %sDocker CE n'est pas disponible pour votre utilisateur et sudo est absent.%s\n" "$C_GOLD" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
        DOCKER_CMDS=$(cat << 'EOF'
# 1. Mise à jour des paquets et prérequis
sudo apt-get update -y && sudo apt-get install -y ca-certificates curl gnupg git git-lfs jq ufw
# 2. Clé GPG officielle Docker
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg
# 3. Dépôt Docker CE
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | sudo tee /etc/apt/sources.list.d/docker.list
# 4. Installation de Docker et du plugin Compose
sudo apt-get update -y && sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
# 5. Démarrage et ajout au groupe docker
sudo systemctl enable --now docker
sudo usermod -aG docker $USER
EOF
)
        print_manual_commands_box "Commandes d'installation Docker CE & Compose" "$DOCKER_CMDS" "${DESC_DOCKER:-}"
        DOCKER_NO_SUDO_MENU=(
            "[Ignorer cette étape] Poursuivre (je configure Docker moi-même)"
            "[Afficher les commandes] Revoir les commandes d'installation"
        )
        gum_choose "Dépendances système & Docker CE" "${DOCKER_NO_SUDO_MENU[@]}" && DOCKER_SELECTION=$? || DOCKER_SELECTION=$?
        STEP_DOCKER="skip"
    elif [ "$STEP_DOCKER" = "auto" ]; then
        DOCKER_CMDS="$CMDS_DOCKER"
        print_manual_commands_box "Commandes prévues : Installation Docker & Compose" "$DOCKER_CMDS" "${DESC_DOCKER:-}"
        DOCKER_MENU=(
            "[Auto] Exécuter ces commandes pour installer Docker & Compose (Recommandé)"
            "[Ignorer] Je gère l'installation de Docker moi-même"
        )
        gum_choose "Dépendances système & Docker CE" "${DOCKER_MENU[@]}" && DOCKER_SELECTION=$? || DOCKER_SELECTION=$?
        case "$DOCKER_SELECTION" in
            0) STEP_DOCKER="auto" ;;
            1) STEP_DOCKER="skip" ;;
        esac
    fi

    # --------------------------------------------------------------------------
    # ÉTAPE SYSTÈME 2 : Port SSH Git et Bascule OpenSSH
    # --------------------------------------------------------------------------
    if [ "$STEP_SSHD" = "skip" ]; then
        printf "  %sℹ%s %sTransition du port SSH hôte : ignoré (décoché par l'utilisateur)%s\n" "$C_CYAN" "$C_RESET" "$C_MUTED" "$C_RESET"
    elif [ "$IS_ROOT" -eq 0 ] && [ "$USE_SUDO" != "1" ]; then
        STEP_SSHD="skip"
        SSH_PORT="${SSH_PORT:-22}"
    elif [ "$SSHD_ON_PORT_22" -eq 1 ]; then
        printf "\n  %s!%s %sLe port 22 est actuellement occupé par le serveur OpenSSH hôte (sshd).%s\n" "$C_GOLD" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
        SSHD_CMDS="$CMDS_SSHD"
        print_manual_commands_box "Commandes prévues : Migration OpenSSH vers port 2222" "$SSHD_CMDS" "${DESC_SSHD:-}"
        SSHD_MENU=(
            "[Auto] Basculer OpenSSH hôte sur le port 2222 (Port 22 pour Git)"
            "[Port alternatif] Garder OpenSSH sur 22 et utiliser 2223 pour Git"
            "[Ignorer] Ne pas modifier la configuration OpenSSH"
        )
        gum_choose "Transition du port SSH hôte (Libération du port 22 pour Git)" "${SSHD_MENU[@]}" && SSHD_SELECTION=$? || SSHD_SELECTION=$?
        case "$SSHD_SELECTION" in
            0)
                STEP_SSHD="auto"
                SSH_PORT="22"
                ;;
            1)
                STEP_SSHD="skip"
                SSH_PORT="2223"
                ;;
            2)
                STEP_SSHD="skip"
                ;;
        esac
    else
        printf "  %s✔%s %sPort SSH Git : ${C_BOLD}${C_GREEN}Port 22 déjà libre${C_RESET} %s(OpenSSH hôte non présent sur le port 22)%s\n" "$C_GREEN" "$C_RESET" "$C_WHITE" "$C_MUTED" "$C_RESET"
        STEP_SSHD="skip"
        SSH_PORT="${SSH_PORT:-22}"
    fi

    # --------------------------------------------------------------------------
    # ÉTAPE SYSTÈME 3 : Pare-feu UFW
    # --------------------------------------------------------------------------
    if [ "$STEP_FIREWALL" = "skip" ]; then
        printf "  %sℹ%s %sConfiguration du pare-feu hôte : ignoré (décoché par l'utilisateur)%s\n" "$C_CYAN" "$C_RESET" "$C_MUTED" "$C_RESET"
    elif [ "$IS_ROOT" -eq 0 ] && [ "$USE_SUDO" != "1" ]; then
        printf "  %sℹ%s %sPare-feu UFW : ignoré en mode applicatif utilisateur (sudo requis pour modifier les règles).%s\n" "$C_CYAN" "$C_RESET" "$C_MUTED" "$C_RESET"
        STEP_FIREWALL="skip"
    elif [ "$STEP_FIREWALL" = "auto" ]; then
        UFW_INSTALLED=0
        if command -v ufw >/dev/null 2>&1; then
            UFW_INSTALLED=1
        fi

        UFW_PREVIEW_INSTALL=""
        if [ "$UFW_INSTALLED" -eq 0 ]; then
            UFW_PREVIEW_INSTALL="# 0. Installation de UFW via le gestionnaire de paquets
$(pkg_install_cmd ufw)
"
        fi

        UFW_CMDS=$(cat << EOF
${UFW_PREVIEW_INSTALL}# 1. Autoriser le port SSH d'administration du système
ufw allow 2222/tcp comment 'System OpenSSH'
# 2. Autoriser HTTP (80) et HTTPS (443) pour Caddy SSL
ufw allow 80/tcp comment 'Gickers HTTP / ACME'
ufw allow 443/tcp comment 'Gickers HTTPS'
# 3. Autoriser le port Git SSH public
ufw allow ${SSH_PORT:-22}/tcp comment 'Gickers Git SSH'
# 4. Activer le pare-feu
ufw --force enable
EOF
)
        if [ "$UFW_INSTALLED" -eq 1 ]; then
            print_manual_commands_box "Commandes prévues : Règles de pare-feu UFW" "$UFW_CMDS" "${DESC_UFW:-}"
            UFW_MENU=(
                "[Auto] Appliquer les règles UFW (Ports 80, 443, ${SSH_PORT:-22}, 2222)"
                "[Ignorer] Je gère mes règles de pare-feu moi-même"
            )
            gum_choose "Configuration du pare-feu hôte (UFW)" "${UFW_MENU[@]}" && UFW_SELECTION=$? || UFW_SELECTION=$?
            case "$UFW_SELECTION" in
                0) STEP_FIREWALL="auto" ;;
                1) STEP_FIREWALL="skip" ;;
            esac
        else
            printf "\n  %s!%s %sLe pare-feu UFW n'est pas installé sur cette machine.%s\n" "$C_GOLD" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
            print_manual_commands_box "Commandes prévues : Installation et configuration UFW" "$UFW_CMDS" "${DESC_UFW:-}"
            UFW_MENU=(
                "[Auto] Installer UFW et appliquer les règles de sécurité (Recommandé)"
                "[Ignorer] Ne pas installer de pare-feu pour le moment"
            )
            gum_choose "Pare-feu hôte (UFW non installé)" "${UFW_MENU[@]}" && UFW_SELECTION=$? || UFW_SELECTION=$?
            case "$UFW_SELECTION" in
                0) STEP_FIREWALL="auto" ;;
                1) STEP_FIREWALL="skip" ;;
            esac
        fi
    fi

    # --------------------------------------------------------------------------
    # ÉTAPE SYSTÈME 4 : Service Systemd (gickers.service)
    # --------------------------------------------------------------------------
    if [ "$STEP_SYSTEMD" = "skip" ]; then
        printf "  %sℹ%s %sService systemd gickers.service : ignoré (décoché par l'utilisateur)%s\n" "$C_CYAN" "$C_RESET" "$C_MUTED" "$C_RESET"
    elif [ "$IS_ROOT" -eq 0 ] && [ "$USE_SUDO" != "1" ]; then
        printf "  %sℹ%s %sService systemd : ignoré en mode applicatif (sudo requis pour /etc/systemd/system).%s\n" "$C_CYAN" "$C_RESET" "$C_MUTED" "$C_RESET"
        STEP_SYSTEMD="skip"
    elif [ "$SYSTEMD_GICKERS_EXISTS" -eq 1 ]; then
        printf "  %s✔%s %sService systemd : ${C_BOLD}${C_GREEN}gickers.service déjà présent${C_RESET}\n" "$C_GREEN" "$C_RESET" "$C_WHITE"
        STEP_SYSTEMD="skip"
    elif [ "$STEP_SYSTEMD" = "auto" ]; then
        SYSTEMD_CMDS="$CMDS_SYSTEMD"
        print_manual_commands_box "Commandes prévues : Création du service de démarrage" "$SYSTEMD_CMDS" "${DESC_SYSTEMD:-}"
        SYSTEMD_MENU=(
            "[Auto] Créer et activer le service de démarrage (au boot)"
            "[Ignorer] Gérer les conteneurs manuellement via compose"
        )
        gum_choose "Intégration service au boot du VPS" "${SYSTEMD_MENU[@]}" && SYSTEMD_SELECTION=$? || SYSTEMD_SELECTION=$?
        case "$SYSTEMD_SELECTION" in
            0) STEP_SYSTEMD="auto" ;;
            1) STEP_SYSTEMD="skip" ;;
        esac
    fi

    # --------------------------------------------------------------------------
    # Formulaire de configuration applicative
    # --------------------------------------------------------------------------
    if [ "${SKIP_APP_DEPLOY:-0}" -eq 1 ]; then
        printf "  %sℹ%s %sDéploiement applicatif : ignoré (décoché par l'utilisateur)%s\n" "$C_CYAN" "$C_RESET" "$C_MUTED" "$C_RESET"
    else
        # 0. Utilisateur système dédié et isolation
        USER_OPTS=()
        if [ "$IS_ROOT" -eq 1 ] || [ "$USE_SUDO" = "1" ]; then
            USER_OPTS+=(
                "[Recommandé] Créer l'utilisateur système dédié (gickers)"
                "Utiliser l'utilisateur actuel ($CURRENT_USER)"
                "Spécifier un autre utilisateur système..."
            )
            gum_choose "Utilisateur système pour exécuter gickers.sh" "${USER_OPTS[@]}" && USER_SELECTION=$? || USER_SELECTION=$?
            case "$USER_SELECTION" in
                0)
                    SYSTEM_USER="gickers"
                    if [ -z "$PROJECT_ROOT" ]; then
                        INSTALL_DIR="/home/gickers/gickers.sh"
                    fi
                    ;;
                1)
                    SYSTEM_USER="$CURRENT_USER"
                    if [ -z "$PROJECT_ROOT" ]; then
                        if [ "$CURRENT_USER" = "root" ]; then
                            INSTALL_DIR="/var/www/gickers"
                        else
                            INSTALL_DIR="$HOME/gickers.sh"
                        fi
                    fi
                    ;;
                2)
                    gum_input "Nom de l'utilisateur système" SYSTEM_USER "gickers"
                    if [ -z "$PROJECT_ROOT" ]; then
                        INSTALL_DIR="/home/$SYSTEM_USER/gickers.sh"
                    fi
                    ;;
            esac
        fi

        # 1. Target Domain
        gum_input "Domaine public (FQDN)" DOMAIN "$DOMAIN" "ex: gits.run ou code.mon-domaine.com"
        DOMAIN=$(echo "$DOMAIN" | tr -d '[:space:]')

        # 2. Application Name
        gum_input "Nom d'usage de la forge" APP_NAME "$APP_NAME" "ex: Gickers.sh"

        # 3. Installation Directory
        gum_input "Répertoire d'installation" INSTALL_DIR "$INSTALL_DIR"
        INSTALL_DIR="${INSTALL_DIR/#\~/$HOME}"

        # 4. Web Ports (HTTP / HTTPS)
        HTTP_OPTS=(
            "Ports standard 80 & 443 (Certificats SSL auto Let's Encrypt / Caddy)"
            "Personnaliser les ports Web (ex: reverse-proxy existant)"
        )
        gum_choose "Configuration des ports Web" "${HTTP_OPTS[@]}" && WEB_CHOICE=$? || WEB_CHOICE=$?
        if [ "$WEB_CHOICE" -eq 1 ]; then
            gum_input "Port HTTP public" HTTP_PORT "$HTTP_PORT"
            gum_input "Port HTTPS public" HTTPS_PORT "$HTTPS_PORT"
        fi

        # 5. Admin SSH Key
        KEY_OPTS=()
        if [ -n "$DETECTED_ADMIN_KEY" ]; then
            KEY_SHORT=$(echo "$DETECTED_ADMIN_KEY" | awk '{print $1, substr($2,1,16)"...", $3}')
            KEY_OPTS+=("Utiliser la clé détectée localement (${KEY_SHORT})")
        fi
        KEY_OPTS+=(
            "Générer une clé SSH admin dédiée (ed25519)"
            "Coller une clé publique SSH (.pub)"
            "Onboarding client sécurisé via token (ssh gickers.sh signup --token <token> | bash)"
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
                ( ssh-keygen -t ed25519 -f "$ADMIN_KEY_PATH" -C "admin@${DOMAIN}" -N "$ADMIN_PASSPHRASE" >/dev/null 2>&1 ) < /dev/null &
                gum_spin $! "Génération de la clé SSH ed25519..."
            fi
            if [ -f "${ADMIN_KEY_PATH}.pub" ]; then
                ADMIN_SSH_KEY=$(cat "${ADMIN_KEY_PATH}.pub")
                printf "  %s✔%s %sClé admin configurée : ${BG_BADGE} ${C_BOLD}${C_GOLD}%s ${C_RESET}\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$ADMIN_KEY_PATH"
            fi
        elif [ "$KEY_ACTION" = "paste" ]; then
            gum_paste_pubkey ADMIN_SSH_KEY
        fi

        # 6. Admin Username (uniquement si une clé admin a été configurée)
        if [ -n "$ADMIN_SSH_KEY" ]; then
            DEFAULT_ADMIN_USER="${ADMIN_USER:-admin}"
            gum_input "Nom d'utilisateur administrateur" ADMIN_USER "$DEFAULT_ADMIN_USER"
            ADMIN_USER=$(echo "$ADMIN_USER" | tr -d '[:space:]')
        else
            ADMIN_USER=""
        fi

        # 7. Server Access Policy
        POLICY_OPTS=(
            "Lecture seule publique (read-only) [Dépôts publics visibles]"
            "Privé strict (no-access) [Authentification SSH requise]"
            "Public complet (public) [Dépôts et création ouverts]"
        )
        gum_choose "Politique d'accès anonyme" "${POLICY_OPTS[@]}" && POL_CHOICE=$? || POL_CHOICE=$?
        case "$POL_CHOICE" in
            0) ANON_ACCESS="read-only" ;;
            1) ANON_ACCESS="no-access" ;;
            2) ANON_ACCESS="public" ;;
        esac

        SIGNUP_OPTS=(
            "Inscriptions en libre-service activées (true) [Recommandé]"
            "Inscriptions fermées (false) [Serveur privé]"
        )
        gum_choose "Inscriptions de nouveaux utilisateurs" "${SIGNUP_OPTS[@]}" && SIGN_CHOICE=$? || SIGN_CHOICE=$?
        [ "$SIGN_CHOICE" -eq 0 ] && ALLOW_SIGNUP="true" || ALLOW_SIGNUP="false"

        # 8. Personnalisation avancée des variables d'environnement (Smart Defaults UI)
        ENV_FILE_DETECTED=""
        if [ -f "$INSTALL_DIR/.env.docker" ]; then
            ENV_FILE_DETECTED="$INSTALL_DIR/.env.docker"
        elif [ -f "./.env.docker" ]; then
            ENV_FILE_DETECTED="./.env.docker"
        elif [ -f "$INSTALL_DIR/.env.local" ]; then
            ENV_FILE_DETECTED="$INSTALL_DIR/.env.local"
        fi

        if [ -n "$ENV_FILE_DETECTED" ] && [ -r "$ENV_FILE_DETECTED" ]; then
            PREV_SECRET=$(grep -E '^APP_SECRET=' "$ENV_FILE_DETECTED" 2>/dev/null | cut -d '=' -f2- || true)
            PREV_PASS=$(grep -E '^POSTGRES_PASSWORD=' "$ENV_FILE_DETECTED" 2>/dev/null | cut -d '=' -f2- || true)
            PREV_USER=$(grep -E '^POSTGRES_USER=' "$ENV_FILE_DETECTED" 2>/dev/null | cut -d '=' -f2- || true)
            PREV_DB=$(grep -E '^POSTGRES_DB=' "$ENV_FILE_DETECTED" 2>/dev/null | cut -d '=' -f2- || true)
            [ -n "$PREV_SECRET" ] && APP_SECRET="$PREV_SECRET"
            [ -n "$PREV_PASS" ] && POSTGRES_PASSWORD="$PREV_PASS"
            [ -n "$PREV_USER" ] && POSTGRES_USER="$PREV_USER"
            [ -n "$PREV_DB" ] && POSTGRES_DB="$PREV_DB"
        fi

        [ -z "${APP_SECRET:-}" ] && APP_SECRET=$(openssl rand -hex 16 2>/dev/null || date +%s%N | sha256sum | head -c 32)
        [ -z "${POSTGRES_PASSWORD:-}" ] && POSTGRES_PASSWORD=$(openssl rand -base64 24 2>/dev/null | tr -dc 'a-zA-Z0-9' | head -c 24 || echo "gickers_db_pass_$(date +%s)")

        printf "\n"
        printf "  %s⚙️  Variables d'environnement & Secrets (Valeurs recommandées)%s\n" "$C_BOLD$C_CYAN" "$C_RESET"
        if [ "$MODE" = "native" ]; then
            printf "    %s•%s %sAPP_SECRET      :%s %s%s...%s (Clé secrète Symfony)\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$C_RESET" "$C_BOLD$C_GOLD" "${APP_SECRET:0:12}" "$C_RESET"
            printf "    %s•%s %sBase de données :%s %ssqlite:///%s/.data/gickers-sh.db%s\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$C_RESET" "$C_GREEN" "%kernel.project_dir%" "$C_RESET"
            printf "    %s•%s %sOptions         :%s Git LFS: %s%s%s • Inscriptions: %s%s%s\n\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$C_RESET" "$C_CYAN" "$LFS_ENABLED" "$C_RESET" "$C_CYAN" "$ALLOW_SIGNUP" "$C_RESET"
        else
            printf "    %s•%s %sAPP_SECRET      :%s %s%s...%s (Clé secrète Symfony)\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$C_RESET" "$C_BOLD$C_GOLD" "${APP_SECRET:0:12}" "$C_RESET"
            printf "    %s•%s %sBase de données :%s %s%s@postgres:5432/%s%s (Mot de passe fort généré)\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$C_RESET" "$C_GREEN" "$POSTGRES_USER" "$POSTGRES_DB" "$C_RESET"
            printf "    %s•%s %sOptions         :%s Git LFS: %s%s%s • Inscriptions: %s%s%s\n\n" "$C_CYAN" "$C_RESET" "$C_WHITE" "$C_RESET" "$C_CYAN" "$LFS_ENABLED" "$C_RESET" "$C_CYAN" "$ALLOW_SIGNUP" "$C_RESET"
        fi

        ENV_MENU=(
            "Conserver ces valeurs recommandées (Automatique & Sécurisé - Entrée rapide)"
            "Personnaliser les variables d'environnement (Base de données, Secret, LFS)..."
        )
        gum_choose "Personnalisation des variables d'environnement" "${ENV_MENU[@]}" && ENV_CHOICE=$? || ENV_CHOICE=$?
        if [ "$ENV_CHOICE" -eq 1 ]; then
            gum_input "Clé APP_SECRET (Symfony)" APP_SECRET "$APP_SECRET" "ex: clé secrète aléatoire de 32 caractères"
            if [ "$MODE" != "native" ]; then
                gum_input "Utilisateur PostgreSQL" POSTGRES_USER "$POSTGRES_USER"
                gum_input "Nom de la base PostgreSQL" POSTGRES_DB "$POSTGRES_DB"
                gum_input "Mot de passe PostgreSQL" POSTGRES_PASSWORD "$POSTGRES_PASSWORD"
            fi
            LFS_OPTS=(
                "Activer Git LFS (true) [Recommandé pour gros fichiers]"
                "Désactiver Git LFS (false)"
            )
            gum_choose "Support de Git LFS (Large File Storage)" "${LFS_OPTS[@]}" && LFS_CHOICE=$? || LFS_CHOICE=$?
            [ "$LFS_CHOICE" -eq 0 ] && LFS_ENABLED="true" || LFS_ENABLED="false"
        fi
    fi

    # 8. Summary & Confirmation
    printf "\n"
    printf "  %s%sConfiguration finale du déploiement :%s\n" "$C_BOLD" "$C_CYAN" "$C_RESET"
    printf "    • %sMode d'exécution :%s   %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_BOLD$C_WHITE" "$MODE" "$C_RESET"
    printf "    • %sUtilisateur hôte :%s   %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_BOLD$C_GREEN" "$SYSTEM_USER" "$C_RESET"
    printf "    • %sDomaine public :%s     %shttps://%s%s\n" "$C_MUTED" "$C_RESET" "$C_BOLD$C_WHITE" "$DOMAIN" "$C_RESET"
    printf "    • %sNom d'usage :%s        %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_BOLD$C_WHITE" "$APP_NAME" "$C_RESET"
    printf "    • %sRépertoire cible :%s   %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_GOLD" "$INSTALL_DIR" "$C_RESET"
    printf "    • %sPort SSH Git :%s       %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_CYAN" "$SSH_PORT" "$C_RESET"
    printf "    • %sPorts Web :%s          %s%s / %s%s\n" "$C_MUTED" "$C_RESET" "$C_CYAN" "$HTTP_PORT" "$HTTPS_PORT" "$C_RESET"
    if [ -n "$ADMIN_SSH_KEY" ]; then
        printf "    • %sAdmin initial :%s        %s@%s%s\n" "$C_MUTED" "$C_RESET" "$C_GREEN" "$ADMIN_USER" "$C_RESET"
    else
        printf "    • %sAdmin initial :%s        %sOnboarding sécurisé par token (signup --token)%s\n" "$C_MUTED" "$C_RESET" "$C_CYAN" "$C_RESET"
    fi
    printf "    • %sÉtape Docker :%s         %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_WHITE" "$STEP_DOCKER" "$C_RESET"
    printf "    • %sBascule SSHD :%s         %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_WHITE" "$STEP_SSHD" "$C_RESET"
    printf "    • %sPare-feu UFW :%s         %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_WHITE" "$STEP_FIREWALL" "$C_RESET"
    printf "    • %sService Systemd :%s      %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_WHITE" "$STEP_SYSTEMD" "$C_RESET"
    if [ "${HARD_RESET:-0}" -eq 1 ]; then
        printf "    • %sRéinitialisation :%s     %s⚠️  HARD RESET ACTIF (Suppression totale des données)%s\n" "$C_MUTED" "$C_RESET" "$C_BOLD$C_PINK" "$C_RESET"
    fi
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
# 4. Exécution des Étapes Système Sélectionnées
# ------------------------------------------------------------------------------
EXEC_SUDO=""
if [ "$IS_ROOT" -eq 1 ]; then
    EXEC_SUDO=""
elif [ -n "$SUDO_CMD" ]; then
    EXEC_SUDO="sudo"
fi

# A. Utilisateur système dédié et Docker CE
if [ "$IS_ROOT" -eq 1 ] || [ -n "$EXEC_SUDO" ]; then
    # Création de l'utilisateur système dédié si nécessaire
    if [ "$SYSTEM_USER" != "root" ] && ! id -u "$SYSTEM_USER" >/dev/null 2>&1; then
        (
            if [ "$PKG_MGR" = "apk" ]; then
                $EXEC_SUDO adduser -D -s /bin/sh "$SYSTEM_USER" >/dev/null 2>&1 || true
            else
                $EXEC_SUDO useradd -m -s /bin/bash -U "$SYSTEM_USER" >/dev/null 2>&1 || true
            fi
        ) < /dev/null &
        gum_spin $! "Création de l'utilisateur système dédié ($SYSTEM_USER)..."
    fi
fi

if [ "$STEP_DOCKER" = "auto" ] && [ -n "$EXEC_SUDO" -o "$IS_ROOT" -eq 1 ]; then
    run_logged_step "Installation et configuration de Docker & Compose" "/tmp/gickers_docker_install.log" "$PWD" \
        bash -c "
            case '$PKG_MGR' in
                apk)
                    $EXEC_SUDO apk update
                    $EXEC_SUDO apk add --no-cache ca-certificates curl git git-lfs jq ufw docker docker-cli-compose
                    $EXEC_SUDO rc-update add docker boot || true
                    $EXEC_SUDO service docker start || true
                    ;;
                dnf)
                    $EXEC_SUDO dnf install -y dnf-plugins-core
                    $EXEC_SUDO dnf config-manager --add-repo https://download.docker.com/linux/fedora/docker-ce.repo
                    $EXEC_SUDO dnf install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin git git-lfs jq curl ca-certificates
                    $EXEC_SUDO systemctl enable --now docker || true
                    ;;
                pacman)
                    $EXEC_SUDO pacman -Sy --noconfirm docker docker-compose git git-lfs curl jq ufw
                    $EXEC_SUDO systemctl enable --now docker || true
                    ;;
                *)
                    if command -v apt-get >/dev/null 2>&1; then
                        $EXEC_SUDO apt-get update -y
                        $EXEC_SUDO apt-get install -y --no-install-recommends \
                            apt-transport-https ca-certificates curl gnupg git git-lfs jq ufw fail2ban openssl tar
                    fi
                    if ! command -v docker >/dev/null 2>&1; then
                        if [ -d /etc/apt ]; then
                            $EXEC_SUDO install -m 0755 -d /etc/apt/keyrings
                            curl -fsSL 'https://download.docker.com/linux/$OS_NAME/gpg' | $EXEC_SUDO gpg --dearmor -o /etc/apt/keyrings/docker.gpg --yes
                            $EXEC_SUDO chmod a+r /etc/apt/keyrings/docker.gpg
                            CODENAME=\$(. /etc/os-release 2>/dev/null && echo \"\$VERSION_CODENAME\" || echo 'stable')
                            [ -z \"\$CODENAME\" ] && CODENAME='stable'
                            echo \"deb [arch=\$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/$OS_NAME \$CODENAME stable\" \
                                | $EXEC_SUDO tee /etc/apt/sources.list.d/docker.list >/dev/null
                            $EXEC_SUDO apt-get update -y
                            $EXEC_SUDO apt-get install -y --no-install-recommends docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
                            $EXEC_SUDO systemctl enable --now docker || true
                        fi
                    fi
                    ;;
            esac
        "

    if [ "$SYSTEM_USER" != "root" ] && id -u "$SYSTEM_USER" >/dev/null 2>&1; then
        if [ "$PKG_MGR" = "apk" ]; then
            $EXEC_SUDO addgroup "$SYSTEM_USER" docker 2>/dev/null || true
        else
            $EXEC_SUDO usermod -aG docker "$SYSTEM_USER" 2>/dev/null || true
        fi
    fi
    if [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != "root" ]; then
        if [ "$PKG_MGR" = "apk" ]; then
            $EXEC_SUDO addgroup "$SUDO_USER" docker 2>/dev/null || true
        else
            $EXEC_SUDO usermod -aG docker "$SUDO_USER" 2>/dev/null || true
        fi
    elif [ "$IS_ROOT" -eq 0 ]; then
        if [ "$PKG_MGR" = "apk" ]; then
            $EXEC_SUDO addgroup "$CURRENT_USER" docker 2>/dev/null || true
        else
            $EXEC_SUDO usermod -aG docker "$CURRENT_USER" 2>/dev/null || true
        fi
    fi
fi

# B. Bascule OpenSSH hôte vers le port 2222
if [ "$STEP_SSHD" = "auto" ] && [ -n "$EXEC_SUDO" -o "$IS_ROOT" -eq 1 ]; then
    run_logged_step "Bascule sécurisée de OpenSSH hôte vers le port 2222 (port 22 libéré)" "/tmp/gickers_sshd_switch.log" "$PWD" \
        bash -c "
            if command -v ufw >/dev/null 2>&1; then
                $EXEC_SUDO ufw allow 2222/tcp comment 'OpenSSH System (moved from 22)' || true
            fi
            if [ -d /etc/ssh/sshd_config.d ]; then
                echo 'Port 2222' | $EXEC_SUDO tee /etc/ssh/sshd_config.d/99-gickers-ssh-port.conf >/dev/null
                $EXEC_SUDO chmod 644 /etc/ssh/sshd_config.d/99-gickers-ssh-port.conf
            fi
            if [ -f /etc/ssh/sshd_config ]; then
                $EXEC_SUDO sed -i -E 's/^[#[:space:]]*Port[[:space:]]+22[[:space:]]*$/Port 2222/' /etc/ssh/sshd_config
            fi
            if [ '$INIT_SYSTEM' = 'openrc' ]; then
                $EXEC_SUDO rc-service sshd restart || true
            elif systemctl is-active --quiet ssh.socket 2>/dev/null; then
                $EXEC_SUDO mkdir -p /etc/systemd/system/ssh.socket.d
                echo -e '[Socket]\nListenStream=\nListenStream=2222' | $EXEC_SUDO tee /etc/systemd/system/ssh.socket.d/listen-2222.conf >/dev/null
                $EXEC_SUDO systemctl daemon-reload
                $EXEC_SUDO systemctl restart ssh.socket || true
            else
                $EXEC_SUDO systemctl restart ssh || $EXEC_SUDO systemctl restart sshd || true
            fi
        "

    printf "\n  %s!%s %s[IMPORTANT] OpenSSH hôte écoute désormais sur le port 2222 :%s\n" "$C_GOLD" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
    printf "    %sssh -p 2222 $(whoami)@<IP_VPS>%s\n\n" "$C_CYAN" "$C_RESET"
fi

# C. Règles de pare-feu UFW
if [ "$STEP_FIREWALL" = "auto" ] && [ -n "$EXEC_SUDO" -o "$IS_ROOT" -eq 1 ]; then
    run_logged_step "Installation et configuration des règles de pare-feu UFW" "/tmp/gickers_ufw.log" "$PWD" \
        bash -c "
            if ! command -v ufw >/dev/null 2>&1; then
                case '$PKG_MGR' in
                    apk)    $EXEC_SUDO apk add --no-cache ufw ;;
                    dnf)    $EXEC_SUDO dnf install -y ufw ;;
                    pacman) $EXEC_SUDO pacman -S --noconfirm ufw ;;
                    *)      $EXEC_SUDO apt-get update -y && $EXEC_SUDO apt-get install -y --no-install-recommends ufw ;;
                esac
            fi

            if command -v ufw >/dev/null 2>&1; then
                CURRENT_SSH=\$(ss -tlnp 2>/dev/null | grep -E 'sshd|ssh' | awk '{print \$4}' | awk -F: '{print \$NF}' | head -n 1 || echo '2222')
                [ -z \"\$CURRENT_SSH\" ] && CURRENT_SSH='2222'
                $EXEC_SUDO ufw allow \"\$CURRENT_SSH\"/tcp comment 'Host SSH daemon' || true
                $EXEC_SUDO ufw allow '$HTTP_PORT'/tcp comment 'Gickers HTTP' || true
                $EXEC_SUDO ufw allow '$HTTPS_PORT'/tcp comment 'Gickers HTTPS' || true
                if [ '$SSH_PORT' != \"\$CURRENT_SSH\" ]; then
                    $EXEC_SUDO ufw allow '$SSH_PORT'/tcp comment 'Gickers Git SSH' || true
                fi
                $EXEC_SUDO ufw --force enable || true
            fi
        "
fi

# ------------------------------------------------------------------------------
# 5. Répertoire Applicatif et Synchronisation du Projet
# ------------------------------------------------------------------------------
if [ "${SKIP_APP_DEPLOY:-0}" -eq 0 ]; then
    mkdir -p "$INSTALL_DIR"
    cd "$INSTALL_DIR"

    # Exécution du Hard Reset si demandé et confirmé
    if [ "${HARD_RESET:-0}" -eq 1 ]; then
        (
            if command -v docker >/dev/null 2>&1; then
                docker compose --env-file .env.docker down -v --remove-orphans >/dev/null 2>&1 || docker compose down -v --remove-orphans >/dev/null 2>&1 || true
                docker volume rm -f gickers-sh_gickers-data gickers-sh_gickers-db gickers-sh_gickers-var gickers-sh_gickers-deploy-engine >/dev/null 2>&1 || true
            fi
            if [ -f .env.docker ]; then
                mv .env.docker .env.docker.reset.$(date +%s).bak 2>/dev/null || rm -f .env.docker
            fi
            rm -rf .data var/cache var/log 2>/dev/null || true
        ) < /dev/null &
        gum_spin $! "Exécution du Hard Reset (destruction des conteneurs, volumes et bases de données)..."
        printf "  %s✔%s %sHard reset terminé avec succès. Démarrage d'une installation vierge.%s\n\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"

        APP_SECRET=""
        POSTGRES_PASSWORD=""
        ADMIN_CLAIM_TOKEN=""
        ADMIN_SSH_KEY=""
    fi

    if [ -d "$INSTALL_DIR/.git" ]; then
        run_logged_step "Mise à jour du projet gickers.sh vers la dernière version" "/tmp/gickers_git_pull.log" "$INSTALL_DIR" \
            bash -c "git fetch --depth 1 origin main && git reset --hard origin/main || git pull --rebase origin main"
    elif [ ! -f "$INSTALL_DIR/compose.yaml" ]; then
        if [ -n "$PROJECT_ROOT" ] && [ -f "$PROJECT_ROOT/compose.yaml" ] && [ "$PROJECT_ROOT" != "$INSTALL_DIR" ]; then
            run_logged_step "Copie des fichiers sources vers $INSTALL_DIR" "/tmp/gickers_source_copy.log" "$INSTALL_DIR" \
                bash -c "tar --exclude='.git' --exclude='.data' --exclude='var' -cf - -C '$PROJECT_ROOT' . | tar -xf - -C '$INSTALL_DIR'"
        else
            run_logged_step "Téléchargement du projet gickers.sh" "/tmp/gickers_git_clone.log" "$INSTALL_DIR" \
                bash -c "git clone --depth 1 https://github.com/gickerssh/gickers.sh.git '$INSTALL_DIR' || git clone --depth 1 https://github.com/gickerssh/gickers.sh.git . || true"
        fi
    fi

    mkdir -p .data .data/caddy/sites var var/cache var/log
    chmod 755 .data .data/caddy/sites var 2>/dev/null || true
    [ ! -f .env ] && touch .env 2>/dev/null || true

    # Si l'utilisateur actuel peut sudo ou si root, s'assurer que le répertoire appartient à CURRENT_USER
    if [ "$IS_ROOT" -eq 1 ]; then
        if [ "$SYSTEM_USER" != "root" ] && id -u "$SYSTEM_USER" >/dev/null 2>&1; then
            chown -R "$SYSTEM_USER":"$SYSTEM_USER" "$INSTALL_DIR" 2>/dev/null || true
        fi
    elif [ -n "$EXEC_SUDO" ]; then
        $EXEC_SUDO chown -R "$CURRENT_USER" "$INSTALL_DIR" 2>/dev/null || true
    fi

    # --------------------------------------------------------------------------
    # 6. Configuration de l'Environnement (.env.docker ou .env.local)
    # --------------------------------------------------------------------------
    if [ -z "${APP_SECRET:-}" ]; then
        APP_SECRET=$(openssl rand -hex 16 2>/dev/null || date +%s%N | sha256sum | head -c 32)
    fi
    if [ -z "${POSTGRES_PASSWORD:-}" ]; then
        POSTGRES_PASSWORD=$(openssl rand -base64 24 2>/dev/null | tr -dc 'a-zA-Z0-9' | head -c 24 || echo "gickers_secret_pass_$(date +%s)")
    fi
    ADMIN_CLAIM_TOKEN=""
    if [ -z "$ADMIN_SSH_KEY" ]; then
        ADMIN_CLAIM_TOKEN=$(openssl rand -hex 16 2>/dev/null || date +%s%N | sha256sum | head -c 32)
    fi

    if [ "$SSH_PORT" = "22" ]; then
        SOFT_SERVE_SSH_URL="ssh://${DOMAIN}"
    else
        SOFT_SERVE_SSH_URL="ssh://${DOMAIN}:${SSH_PORT}"
    fi

    if [ "$MODE" = "native" ]; then
        # Configuration Native (.env.local)
        cat > .env.local << EOF
# ==============================================================================
# Native Host Environment for Gickers.sh (Domain: ${DOMAIN})
# Generated automatically on $(date -u +"%Y-%m-%dT%H:%M:%SZ" 2>/dev/null || date)
# ==============================================================================

APP_ENV=prod
APP_DEBUG=0
APP_SECRET=${APP_SECRET}
APP_DOMAIN=${DOMAIN}

# Database SQLite locale
DATABASE_URL="sqlite:///%kernel.project_dir%/.data/gickers-sh.db"
MESSENGER_TRANSPORT_DSN="doctrine://default?auto_setup=0"

# Ports et URLs publiques
SOFT_SERVE_NAME="${APP_NAME}"
SOFT_SERVE_SSH_LISTEN_ADDR="0.0.0.0:${SSH_PORT}"
SOFT_SERVE_SSH_PUBLIC_URL="${SOFT_SERVE_SSH_URL}"
SOFT_SERVE_HTTP_LISTEN_ADDR="0.0.0.0:${HTTP_PORT}"
SOFT_SERVE_HTTP_PUBLIC_URL="http://${DOMAIN}:${HTTP_PORT}"

# Politiques & Accès
SOFT_SERVE_LFS_ENABLED=${LFS_ENABLED}
SOFT_SERVE_ANON_ACCESS=${ANON_ACCESS}
SOFT_SERVE_ALLOW_KEYLESS=${ALLOW_KEYLESS}
SOFT_SERVE_INITIAL_ADMIN_KEYS="${ADMIN_SSH_KEY}"
SOFT_SERVE_INITIAL_ADMIN_TOKEN="${ADMIN_CLAIM_TOKEN}"
SOFT_SERVE_ALLOW_SIGNUP=${ALLOW_SIGNUP}
EOF
        chmod 600 .env.local 2>/dev/null || true
        printf "  %s✔%s %sConfiguration native .env.local initialisée.%s
" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"

        # ----------------------------------------------------------------------
        # 7. Build et Démarrage Natif
        # ----------------------------------------------------------------------
        deploy_log="/tmp/gickers_deploy.log"
        : > "$deploy_log"

        (
            if [ ! -f vendor/autoload.php ]; then
                composer install --no-dev --optimize-autoloader >> "$deploy_log" 2>&1
            fi
            chmod +x bin/soft bin/console
            if [ ! -x bin/gickers-ssh ]; then
                ( cd cmd/gickers-ssh && go build -o ../../bin/gickers-ssh . >> "$deploy_log" 2>&1 )
            fi
            ln -sf gickers-ssh bin/codes-ssh 2>/dev/null || true
        ) < /dev/null &
        if ! gum_spin $! "Compilation du gateway gickers-ssh et installation Composer..."; then
            printf "
  %s✖%s %sÉchec de la compilation / installation native.%s
" "$C_PINK" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
            tail -n 25 "$deploy_log" | sed 's/^/    /'
            exit 1
        fi

        (
            ./bin/soft doctrine:migrations:migrate --allow-no-migration --no-interaction >> "$deploy_log" 2>&1
        ) < /dev/null &
        if ! gum_spin $! "Exécution des migrations SQLite & initialisation Doctrine..."; then
            printf "
  %s✖%s %sÉchec des migrations Doctrine.%s
" "$C_PINK" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
            tail -n 25 "$deploy_log" | sed 's/^/    /'
            exit 1
        fi
    else
        # Configuration Docker (.env.docker)
        if [ -f .env.docker ]; then
            if [ ! -r .env.docker ]; then
                if [ -n "$EXEC_SUDO" ]; then
                    $EXEC_SUDO chmod 644 .env.docker 2>/dev/null || true
                    $EXEC_SUDO chown "$CURRENT_USER" .env.docker 2>/dev/null || true
                else
                    chmod 644 .env.docker 2>/dev/null || true
                fi
            fi

            if [ -r .env.docker ]; then
                cp .env.docker .env.docker.bak 2>/dev/null || true
                EXISTING_SECRET=$(grep -E '^APP_SECRET=' .env.docker 2>/dev/null | cut -d '=' -f2- || true)
                EXISTING_PG_PASS=$(grep -E '^POSTGRES_PASSWORD=' .env.docker 2>/dev/null | cut -d '=' -f2- || true)
                EXISTING_CLAIM_TOKEN=$(grep -E '^SOFT_SERVE_INITIAL_ADMIN_TOKEN=' .env.docker 2>/dev/null | cut -d '=' -f2- | tr -d '"' || true)
                EXISTING_ADMIN_KEYS=$(grep -E '^SOFT_SERVE_INITIAL_ADMIN_KEYS=' .env.docker 2>/dev/null | cut -d '=' -f2- | tr -d '"' || true)
                if [ -n "$EXISTING_SECRET" ]; then
                    APP_SECRET="$EXISTING_SECRET"
                fi
                if [ -n "$EXISTING_PG_PASS" ]; then
                    POSTGRES_PASSWORD="$EXISTING_PG_PASS"
                fi
                if [ -n "$EXISTING_CLAIM_TOKEN" ] && [ -z "$ADMIN_SSH_KEY" ]; then
                    ADMIN_CLAIM_TOKEN="$EXISTING_CLAIM_TOKEN"
                fi
                if [ -n "$EXISTING_ADMIN_KEYS" ] && [ -z "$ADMIN_SSH_KEY" ]; then
                    ADMIN_SSH_KEY="$EXISTING_ADMIN_KEYS"
                fi
            fi
        fi

        if [ -w . ] || [ -w .env.docker 2>/dev/null ]; then
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
SOFT_SERVE_INITIAL_ADMIN_TOKEN="${ADMIN_CLAIM_TOKEN}"
SOFT_SERVE_ALLOW_SIGNUP=${ALLOW_SIGNUP}
SOFT_SERVE_WEBHOOK_ALLOW_LIST=

# Isolated Deploy Engine (Docker-in-Docker with gVisor sandbox & anti-SSRF)
DOCKER_HOST=tcp://deploy-dockerd:2375
DEPLOY_GATEWAY_HOST=deploy-dockerd
DEPLOY_DOCKER_RUNTIME=runsc
DEPLOY_GVISOR_PLATFORM=systrap
EOF
        else
            cat << EOF | $EXEC_SUDO tee .env.docker >/dev/null
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
SOFT_SERVE_INITIAL_ADMIN_TOKEN="${ADMIN_CLAIM_TOKEN}"
SOFT_SERVE_ALLOW_SIGNUP=${ALLOW_SIGNUP}
SOFT_SERVE_WEBHOOK_ALLOW_LIST=

# Isolated Deploy Engine (Docker-in-Docker with gVisor sandbox & anti-SSRF)
DOCKER_HOST=tcp://deploy-dockerd:2375
DEPLOY_GATEWAY_HOST=deploy-dockerd
DEPLOY_DOCKER_RUNTIME=runsc
DEPLOY_GVISOR_PLATFORM=systrap
EOF
            if [ -n "$EXEC_SUDO" ]; then
                $EXEC_SUDO chown "$CURRENT_USER" .env.docker 2>/dev/null || true
            fi
        fi

        chmod 600 .env.docker 2>/dev/null || true
        printf "  %s✔%s %sConfiguration .env.docker initialisée.%s
" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"

        # ----------------------------------------------------------------------
        # 7. Build et Déploiement Docker Compose
        # ----------------------------------------------------------------------
        deploy_log="/tmp/gickers_deploy.log"

        # A. Build des conteneurs avec affichage complet BuildKit & live streaming
        run_logged_step "Construction des conteneurs Docker (PHP, gickers-ssh, FrankenPHP)" "$deploy_log" "$INSTALL_DIR" \
            docker compose --env-file .env.docker build

        # B. Démarrage de PostgreSQL
        run_logged_step "Démarrage de PostgreSQL" "$deploy_log" "$INSTALL_DIR" \
            docker compose --env-file .env.docker up -d postgres

        # Wait for PostgreSQL readiness
        (
            RETRY=0
            MAX_RETRY=30
            until docker compose --env-file .env.docker exec -T postgres pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB" < /dev/null >/dev/null 2>&1 || [ $RETRY -eq $MAX_RETRY ]; do
                sleep 1
                RETRY=$((RETRY + 1))
            done
            [ $RETRY -lt $MAX_RETRY ]
        ) < /dev/null &
        if ! gum_stream_spin $! "Attente de disponibilité de PostgreSQL..."; then
            printf "  %s!%s %sPostgreSQL ne répond pas encore à pg_isready après 30s, poursuite via depends_on...%s\n" "$C_GOLD" "$C_RESET" "$C_MUTED" "$C_RESET"
        fi

        # C. Exécution des migrations Doctrine & initialisation
        run_logged_step "Exécution des migrations Doctrine & initialisation du serveur" "$deploy_log" "$INSTALL_DIR" \
            docker compose --env-file .env.docker run -T --rm init

        # D. Démarrage complet de la stack Docker Compose
        run_logged_step "Démarrage complet de la stack (HTTP, SSH, Worker, Deploy-Engine)" "$deploy_log" "$INSTALL_DIR" \
            docker compose --env-file .env.docker up -d
    fi
fi

# ------------------------------------------------------------------------------
# 8. Service Système de Démarrage (systemd ou openrc)
# ------------------------------------------------------------------------------
if [ "$STEP_SYSTEMD" = "auto" ] && [ -n "$EXEC_SUDO" -o "$IS_ROOT" -eq 1 ]; then
    SYSTEMD_USER_DIRECTIVE=""
    if [ "$SYSTEM_USER" != "root" ] && id -u "$SYSTEM_USER" >/dev/null 2>&1; then
        SYSTEMD_USER_DIRECTIVE="User=${SYSTEM_USER}
Group=${SYSTEM_USER}"
    fi

    (
        if [ "$INIT_SYSTEM" = "openrc" ]; then
            cat << 'INIT_EOF' | $EXEC_SUDO tee /etc/init.d/gickers >/dev/null
#!/sbin/openrc-run
description="Gickers.sh Git Forge & Container Platform"

depend() {
    need docker
    after network-online
}

start() {
    ebegin "Starting Gickers.sh"
    cd ${INSTALL_DIR} && /usr/bin/docker compose --env-file .env.docker up -d
    eend $?
}

stop() {
    ebegin "Stopping Gickers.sh"
    cd ${INSTALL_DIR} && /usr/bin/docker compose --env-file .env.docker down
    eend $?
}
INIT_EOF
            $EXEC_SUDO chmod +x /etc/init.d/gickers
            $EXEC_SUDO rc-update add gickers default >/dev/null 2>&1 || true
            $EXEC_SUDO rc-service gickers start >/dev/null 2>&1 || true
        else
            if [ "$MODE" = "native" ]; then
                echo "[Unit]
Description=Gickers.sh Native Git Forge & Platform
After=network.target

[Service]
Type=simple
${SYSTEMD_USER_DIRECTIVE}
WorkingDirectory=${INSTALL_DIR}
ExecStart=${INSTALL_DIR}/bin/soft serve
Restart=always
TimeoutStartSec=60

[Install]
WantedBy=multi-user.target" | $EXEC_SUDO tee /etc/systemd/system/gickers.service >/dev/null
            else
                echo "[Unit]
Description=Gickers.sh Git Forge & Container Platform
Requires=docker.service
After=docker.service network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
${SYSTEMD_USER_DIRECTIVE}
WorkingDirectory=${INSTALL_DIR}
ExecStart=/usr/bin/docker compose --env-file .env.docker up -d
ExecStop=/usr/bin/docker compose --env-file .env.docker down
ExecReload=/usr/bin/docker compose --env-file .env.docker restart
TimeoutStartSec=300

[Install]
WantedBy=multi-user.target" | $EXEC_SUDO tee /etc/systemd/system/gickers.service >/dev/null
            fi

            $EXEC_SUDO systemctl daemon-reload >/dev/null 2>&1 || true
            $EXEC_SUDO systemctl enable gickers.service >/dev/null 2>&1 || true
        fi
    ) < /dev/null &
    gum_spin $! "Activation du service au boot de la machine ($INIT_SYSTEM)..."
fi

if [ "${SKIP_APP_DEPLOY:-0}" -eq 0 ] && [ -d "$INSTALL_DIR" ]; then
    if [ "$IS_ROOT" -eq 1 ] || [ -n "$EXEC_SUDO" ]; then
        if [ "$SYSTEM_USER" != "root" ] && id -u "$SYSTEM_USER" >/dev/null 2>&1; then
            $EXEC_SUDO chown -R "$SYSTEM_USER":"$SYSTEM_USER" "$INSTALL_DIR" 2>/dev/null || true
        elif [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != "root" ]; then
            $EXEC_SUDO chown -R "$SUDO_USER":"$SUDO_USER" "$INSTALL_DIR" 2>/dev/null || true
        fi
    fi
fi

# ------------------------------------------------------------------------------
# 9. Affichage de la Carte de Succès
# ------------------------------------------------------------------------------
sleep 1
echo ""

FINAL_SSH_URL="ssh://${DOMAIN}"
FINAL_SSH_CMD="ssh ${DOMAIN}"
if [ "$SSH_PORT" != "22" ] && [ -n "$SSH_PORT" ]; then
    FINAL_SSH_URL="ssh://${DOMAIN}:${SSH_PORT}"
    FINAL_SSH_CMD="ssh -p ${SSH_PORT} ${DOMAIN}"
fi

printf "  %s%s✓ Installation et déploiement de %s terminés avec succès !%s\n" "$C_BOLD" "$C_GREEN" "$APP_NAME" "$C_RESET"
printf "  %sVotre forge Git et plateforme de conteneurs est opérationnelle :%s\n" "$C_MUTED" "$C_RESET"
printf "    • %sInterface Web :%s   https://%s\n" "$C_MUTED" "$C_RESET" "$DOMAIN"
printf "    • %sClone SSH :%s       git clone %s/<user>/<repo>.git\n" "$C_MUTED" "$C_RESET" "$FINAL_SSH_URL"
printf "    • %sClone HTTP :%s      https://%s/<user>/<repo>.git\n" "$C_MUTED" "$C_RESET" "$DOMAIN"
printf "    • %sAccès CLI / TUI :%s %s\n" "$C_MUTED" "$C_RESET" "$FINAL_SSH_CMD"
printf "    • %sInfo serveur :%s    %s info\n\n" "$C_MUTED" "$C_RESET" "$FINAL_SSH_CMD"

if [ -n "$ADMIN_CLAIM_TOKEN" ]; then
    SSH_PORT_CMD_OPT=""
    if [ "$SSH_PORT" != "22" ] && [ -n "$SSH_PORT" ]; then
        SSH_PORT_CMD_OPT="-p ${SSH_PORT} "
    fi
    printf "  %s🔑 Initialisation Administrateur (Token sécurisé à usage unique)%s\n" "$C_BOLD$C_GREEN" "$C_RESET"
    printf "  %sInitialisez votre compte administrateur depuis votre poste client :%s\n\n" "$C_MUTED" "$C_RESET"
    printf "    %s%sssh %s%s signup --token %s | bash%s\n\n" "$C_BOLD" "$C_CYAN" "$SSH_PORT_CMD_OPT" "$DOMAIN" "$ADMIN_CLAIM_TOKEN" "$C_RESET"
    printf "  %sSur Windows (PowerShell) :%s\n" "$C_MUTED" "$C_RESET"
    printf "    %s%sssh %s%s signup --token %s | iex%s\n\n" "$C_BOLD" "$C_CYAN" "$SSH_PORT_CMD_OPT" "$DOMAIN" "$ADMIN_CLAIM_TOKEN" "$C_RESET"
fi
}

main "$@"
