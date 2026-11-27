#!/usr/bin/env bash
# ==============================================================================
#  gickers.sh — One-Shot Production Installer & Deployment Wizard
#  Inspired by Charmbracelet Gum (TokyoNight High Contrast Theme)
# ==============================================================================
set -euo pipefail

# TokyoNight High-Contrast Palette
ESC=$(printf '\033')
C_CYAN="${ESC}[1;38;2;125;207;255m"      # #7dcfff - Electric Cyan
C_PINK="${ESC}[1;38;2;247;118;142m"      # #f7768e - Vibrant Coral Pink
C_GREEN="${ESC}[1;38;2;115;218;202m"     # #73daca - Emerald Teal
C_GOLD="${ESC}[1;38;2;224;175;104m"      # #e0af68 - Warm Gold
C_WHITE="${ESC}[1;38;2;192;202;245m"     # #c0caf5 - Crisp White
C_MUTED="${ESC}[38;2;169;177;214m"       # #a9b1d6 - Legible Secondary Text
C_DIM="${ESC}[38;2;86;95;137m"           # #565f89 - Dimmed Placeholders
C_RESET="${ESC}[0m"
C_BOLD="${ESC}[1m"

# TokyoNight Background Styling
BG_SELECT="${ESC}[48;2;41;46;66m"        # #292e42 - Selection bar background
BG_INPUT="${ESC}[48;2;36;40;59m"         # #24283b - Input pill background
BG_BADGE="${ESC}[48;2;59;66;97m"         # #3b4261 - Badge pill background

TTY_INPUT=""
if [ -e /dev/tty ] && [ -r /dev/tty ]; then
    TTY_INPUT="/dev/tty"
fi

# Gum-styled choose/select component
gum_choose() {
    local prompt_title="$1"
    shift
    local options=("$@")
    local selected=0
    local count=${#options[@]}
    local key=""

    if [ -z "$TTY_INPUT" ] || [ ! -t 1 ]; then
        for i in "${!options[@]}"; do
            echo "  [$((i + 1))] ${options[i]}"
        done
        local choice="1"
        gum_input "Votre choix" choice "1"
        case "$choice" in
            2) return 1 ;;
            3) return 2 ;;
            4) return 3 ;;
            5) return 4 ;;
            6) return 5 ;;
            *) return 0 ;;
        esac
    fi

    local term_cols
    term_cols=$(tput cols 2>/dev/null || echo 80)
    [ -z "$term_cols" ] && term_cols=80
    local max_width=$(( term_cols - 8 ))
    [ "$max_width" -lt 30 ] && max_width=30

    printf "\n  %s?%s %s%s%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_WHITE" "$prompt_title" "$C_RESET"

    draw_items() {
        for i in "${!options[@]}"; do
            local opt_text="${options[i]}"
            if [ "${#opt_text}" -gt "$max_width" ]; then
                opt_text="${opt_text:0:$((max_width - 3))}..."
            fi
            if [ "$i" -eq "$selected" ]; then
                printf "\r${ESC}[2K    ${BG_SELECT} %s❯ %s%s %s\n" "$C_PINK" "$C_BOLD$C_CYAN" "$opt_text" "$C_RESET"
            else
                printf "\r${ESC}[2K      %s%s%s\n" "$C_MUTED" "$opt_text" "$C_RESET"
            fi
        done
    }

    printf "${ESC}[?25l"
    draw_items

    while true; do
        IFS= read -rsn1 key < "$TTY_INPUT" || break
        if [[ $key == $'\x1b' ]]; then
            read -rsn2 -t 0.2 rest < "$TTY_INPUT" || true
            case "$rest" in
                "[A"|"OA")
                    selected=$(( (selected - 1 + count) % count ))
                    printf "\r${ESC}[%dA" "$count"
                    draw_items
                    ;;
                "[B"|"OB")
                    selected=$(( (selected + 1) % count ))
                    printf "\r${ESC}[%dA" "$count"
                    draw_items
                    ;;
            esac
        elif [[ $key == "k" ]]; then
            selected=$(( (selected - 1 + count) % count ))
            printf "\r${ESC}[%dA" "$count"
            draw_items
        elif [[ $key == "j" ]]; then
            selected=$(( (selected + 1) % count ))
            printf "\r${ESC}[%dA" "$count"
            draw_items
        elif [[ $key == "" ]]; then
            break
        elif [[ $key =~ ^[1-9]$ ]] && [ "$key" -le "$count" ]; then
            selected=$(( key - 1 ))
            break
        elif [[ $key == "q" ]]; then
            printf "${ESC}[?25h\n"
            echo "Annulé."
            exit 0
        fi
    done

    # Clear menu lines and collapse into a single confirmed Gum line
    local total_lines=$(( count + 1 ))
    printf "\r${ESC}[%dA${ESC}[J" "$total_lines"
    printf "${ESC}[?25h"

    printf "  %s✔%s %s%s%s : ${BG_BADGE} %s%s %s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$prompt_title" "$C_RESET" "$C_BOLD$C_CYAN" "${options[selected]}" "$C_RESET"

    return "$selected"
}

# Gum-styled input component with placeholder
gum_input() {
    local prompt_label="$1"
    local var_name="$2"
    local default_val="${3:-}"
    local placeholder="${4:-$default_val}"

    if [ -z "$TTY_INPUT" ] || [ ! -t 1 ]; then
        local val=""
        read -r -p "  $prompt_label [$default_val]: " val || val="$default_val"
        printf -v "$var_name" '%s' "${val:-$default_val}"
        return 0
    fi

    local input=""
    local char=""

    printf "${ESC}[?25h"

    redraw_in() {
        printf "\r${ESC}[2K  %s❯%s %s%s%s : " "$C_PINK" "$C_RESET" "$C_BOLD$C_WHITE" "$prompt_label" "$C_RESET"
        if [ -z "$input" ]; then
            printf "${BG_INPUT} %s%s %s${ESC}[%dD" "$C_DIM" "$placeholder" "$C_RESET" "$(( ${#placeholder} + 2 ))"
        else
            printf "${BG_INPUT} %s%s %s" "$C_GOLD" "$input" "$C_RESET"
        fi
    }

    redraw_in

    while true; do
        IFS= read -rsn1 char < "$TTY_INPUT" || break
        if [[ $char == "" ]]; then
            break
        elif [[ $char == $'\x7f' || $char == $'\b' ]]; then
            if [ -n "$input" ]; then
                input="${input%?}"
            fi
            redraw_in
        elif [[ $char == $'\x1b' ]]; then
            read -rsn2 -t 0.05 _r < "$TTY_INPUT" || true
        else
            input+="$char"
            redraw_in
        fi
    done

    local final_val="${input:-$default_val}"
    printf -v "$var_name" '%s' "$final_val"

    printf "\r${ESC}[2K  %s✔%s %s%s%s : ${BG_BADGE} %s%s %s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$prompt_label" "$C_RESET" "$C_GOLD" "$final_val" "$C_RESET"
}

# Gum-styled password input component with masked dots
gum_password() {
    local prompt_label="$1"
    local var_name="$2"
    local placeholder="${3:-(optionnelle, laisser vide)}"

    if [ -z "$TTY_INPUT" ] || [ ! -t 1 ]; then
        local val=""
        read -r -s val || val=""
        printf -v "$var_name" '%s' "$val"
        return 0
    fi

    local input=""
    local char=""

    redraw_pw() {
        printf "\r${ESC}[2K  %s❯%s %s%s%s : " "$C_PINK" "$C_RESET" "$C_BOLD$C_WHITE" "$prompt_label" "$C_RESET"
        if [ -z "$input" ]; then
            printf "${BG_INPUT} %s%s %s${ESC}[%dD" "$C_DIM" "$placeholder" "$C_RESET" "$(( ${#placeholder} + 2 ))"
        else
            local bullets=""
            for (( i=0; i<${#input}; i++ )); do
                bullets+="•"
            done
            printf "${BG_INPUT} %s%s %s" "$C_PINK" "$bullets" "$C_RESET"
        fi
    }

    redraw_pw

    while true; do
        IFS= read -rsn1 char < "$TTY_INPUT" || break
        if [[ $char == "" ]]; then
            break
        elif [[ $char == $'\x7f' || $char == $'\b' ]]; then
            if [ -n "$input" ]; then
                input="${input%?}"
            fi
            redraw_pw
        elif [[ $char == $'\x1b' ]]; then
            read -rsn2 -t 0.05 _r < "$TTY_INPUT" || true
        else
            input+="$char"
            redraw_pw
        fi
    done

    printf -v "$var_name" '%s' "$input"

    local disp_val
    if [ -n "$input" ]; then
        local masked=""
        for (( i=0; i<${#input}; i++ )); do
            masked+="•"
        done
        disp_val="${C_PINK}${masked}"
    else
        disp_val="${C_DIM}(aucune)"
    fi

    printf "\r${ESC}[2K  %s✔%s %s%s%s : ${BG_BADGE} %s %s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$prompt_label" "$C_RESET" "$disp_val" "$C_RESET"
}

# Gum-styled confirmation prompt (Yes/No)
gum_confirm() {
    local prompt_title="$1"
    local default_yes="${2:-1}"
    local opts=("Oui, continuer" "Non, annuler")
    if [ "$default_yes" -eq 0 ]; then
        opts=("Non, annuler" "Oui, continuer")
    fi

    gum_choose "$prompt_title" "${opts[@]}" && local idx=$? || local idx=$?
    if [ "$default_yes" -eq 1 ]; then
        [ "$idx" -eq 0 ] && return 0 || return 1
    else
        [ "$idx" -eq 1 ] && return 0 || return 1
    fi
}

# Password input with double confirmation check
prompt_password_with_confirm() {
    local label="$1"
    local var_name="$2"

    while true; do
        local p1=""
        local p2=""
        gum_password "$label" p1 "(optionnelle, laisser vide)"
        if [ -z "$p1" ]; then
            printf -v "$var_name" '%s' ""
            return 0
        fi

        gum_password "Confirmation de la passphrase" p2 "(retapez votre passphrase)"
        if [ "$p1" = "$p2" ]; then
            printf -v "$var_name" '%s' "$p1"
            return 0
        fi

        printf "  %s✖%s %sLes passphrases ne correspondent pas. Veuillez réessayer.%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$C_RESET"
    done
}

# Backup password input with double confirmation check
prompt_backup_password_with_confirm() {
    local label="$1"
    local var_name="$2"

    while true; do
        local p1=""
        local p2=""
        gum_password "$label" p1 "(minimum 6 caractères)"
        if [ -z "$p1" ]; then
            printf -v "$var_name" '%s' ""
            return 0
        fi

        if [ ${#p1} -lt 6 ]; then
            printf "  %s✖%s %sLe mot de passe doit comporter au moins 6 caractères. Veuillez réessayer.%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$C_RESET"
            continue
        fi

        gum_password "Confirmation du mot de passe" p2 "(retapez votre mot de passe)"
        if [ "$p1" = "$p2" ]; then
            printf -v "$var_name" '%s' "$p1"
            return 0
        fi

        printf "  %s✖%s %sLes mots de passe ne correspondent pas. Veuillez réessayer.%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$C_RESET"
    done
}

# Keypath prompt with file collision detection (Re-use / Overwrite / New path)
prompt_key_path_with_collision_check() {
    local default_p="$1"
    local var_path="$2"
    local var_overwrite="$3"

    local cur_p="$default_p"
    while true; do
        gum_input "Chemin du fichier" cur_p "$cur_p"
        cur_p="${cur_p/#\~/$HOME}"

        if [ -f "$cur_p" ]; then
            printf "\n  %s!%s %sLe fichier %s existe déjà.%s\n" "$C_GOLD" "$C_RESET" "$C_BOLD$C_WHITE" "$cur_p" "$C_RESET"
            local COL_OPTS=(
                "Réutiliser cette clé existante"
                "Écraser et générer une nouvelle clé"
                "Choisir un autre chemin de fichier"
            )
            gum_choose "Action souhaitée" "${COL_OPTS[@]}" && local col_idx=$? || local col_idx=$?
            case "$col_idx" in
                0)
                    printf -v "$var_path" '%s' "$cur_p"
                    printf -v "$var_overwrite" '%d' 0
                    return 0
                    ;;
                1)
                    rm -f "$cur_p" "${cur_p}.pub"
                    printf -v "$var_path" '%s' "$cur_p"
                    printf -v "$var_overwrite" '%d' 1
                    return 0
                    ;;
                2)
                    cur_p="${cur_p}_new"
                    continue
                    ;;
            esac
        else
            printf -v "$var_path" '%s' "$cur_p"
            printf -v "$var_overwrite" '%d' 1
            return 0
        fi
    done
}

# Gum input component for pasting a public key
gum_paste_pubkey() {
    local var_name="$1"
    local pasted=""
    printf "${ESC}[?25h"

    while true; do
        printf "  %s❯%s %sCollez votre clé publique SSH (.pub)%s : " "$C_PINK" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
        if [ -n "$TTY_INPUT" ] && [ -t 1 ]; then
            IFS= read -r pasted < "$TTY_INPUT" || pasted=""
        else
            IFS= read -r pasted || pasted=""
        fi
        pasted=$(echo "$pasted" | tr -d '\r\n' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')

        if [ -z "$pasted" ]; then
            printf "  %s✖%s %sAucune clé collée. Veuillez réessayer.%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$C_RESET"
            continue
        fi

        if [[ ! "$pasted" =~ ^(ssh-ed25519|ssh-rsa|ecdsa-sha2-|sk-ssh-ed25519|sk-ecdsa-) ]]; then
            printf "  %s✖%s %sFormat invalide (doit commencer par ssh-ed25519, ssh-rsa, etc.). Réessayez :%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$C_RESET"
            continue
        fi

        break
    done

    local key_type
    key_type=$(echo "$pasted" | awk '{print $1}')
    local key_comment
    key_comment=$(echo "$pasted" | awk '{print $3}')
    local key_summary="$key_type"
    if [ -n "$key_comment" ]; then
        key_summary="$key_type ($key_comment)"
    fi

    printf "  %s✔%s %sClé publique SSH reçue%s : ${BG_BADGE} ${C_BOLD}${C_GOLD}%s ${C_RESET}\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET" "$key_summary"
    printf -v "$var_name" '%s' "$pasted"
}

# Gum-styled braille spinner
gum_spin() {
    local pid=$1
    local msg="$2"
    local spin_chars=('⠋' '⠙' '⠹' '⠸' '⠼' '⠴' '⠦' '⠧' '⠇' '⠏')
    local i=0
    printf "${ESC}[?25l"
    while kill -0 "$pid" 2>/dev/null; do
        printf "\r  %s%s%s %s%s%s" "$C_PINK" "${spin_chars[i]}" "$C_RESET" "$C_BOLD$C_WHITE" "$msg" "$C_RESET"
        i=$(( (i + 1) % 10 ))
        sleep 0.07
    done
    printf "${ESC}[?25h"
    printf "\r${ESC}[2K  %s✔%s %s%s%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$msg" "$C_RESET"
}

# Backwards compatibility aliases
select_menu() { gum_choose "$@"; }
prompt() { gum_input "$1" "$2" "${3:-}"; }
prompt_secret() { gum_password "$1" "$2"; }
spin() { gum_spin "$@"; }


# Display Termstrap Header
cat << 'EOF'
[38;2;65;72;104m[48;2;31;35;53m╭────────────────────────────────────────────────────────────────────────╮[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m🚀 Gickers.sh — Installation & Déploiement One-Shot [Production VPS &[m[48;2;31;35;53m [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53mLocal][m[48;2;31;35;53m                                                                [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;86;95;137m[38;2;86;95;137mCible : codes.sh • Git Forge & Containers Isolés • Caddy SSL Auto[m[48;2;31;35;53m[m[48;2;31;35;53m[m[48;2;31;35;53m     [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m╰────────────────────────────────────────────────────────────────────────╯[m
                                                                          
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
DOMAIN="codes.sh"
APP_NAME="Gickers.sh"
SSH_PORT="22"
HTTP_PORT="80"
HTTPS_PORT="443"
INSTALL_DIR=""
ADMIN_SSH_KEY=""
ADMIN_USER="admin"
POSTGRES_USER="gickers"
POSTGRES_DB="gickers"
POSTGRES_PASSWORD=""
ANON_ACCESS="read-only"
ALLOW_SIGNUP="true"
ALLOW_KEYLESS="true"
LFS_ENABLED="true"
NON_INTERACTIVE="0"
DRY_RUN="0"
USE_SUDO="0"
STEP_DOCKER="auto"
STEP_SSHD="auto"
STEP_FIREWALL="auto"
STEP_SYSTEMD="auto"
SYSTEM_USER=""

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
        --skip-docker) STEP_DOCKER="skip"; shift ;;
        --skip-sshd-switch) STEP_SSHD="skip"; shift ;;
        --skip-firewall) STEP_FIREWALL="skip"; shift ;;
        --skip-systemd) STEP_SYSTEMD="skip"; shift ;;
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

# Helper to print annotated manual commands in terminal
print_manual_commands_box() {
    local title="$1"
    local commands="$2"
    printf "\n"
    printf "  %s%s╭────────────────────────────────────────────────────────────────────────╮%s\n" "$C_BOLD" "$C_CYAN" "$C_RESET"
    printf "  %s│ %s%-70s%s %s│%s\n" "$C_BOLD$C_CYAN" "$C_WHITE" "$title" "$C_BOLD$C_CYAN" "$C_RESET" "$C_RESET"
    printf "  %s├────────────────────────────────────────────────────────────────────────┤%s\n" "$C_BOLD$C_CYAN" "$C_RESET"
    while IFS= read -r cmdline; do
        if [[ "$cmdline" =~ ^[[:space:]]*# ]]; then
            printf "  %s│ %s%-70s%s %s│%s\n" "$C_BOLD$C_CYAN" "$C_MUTED" "$cmdline" "$C_BOLD$C_CYAN" "$C_RESET" "$C_RESET"
        else
            printf "  %s│ %s%-70s%s %s│%s\n" "$C_BOLD$C_CYAN" "$C_GOLD" "$cmdline" "$C_BOLD$C_CYAN" "$C_RESET" "$C_RESET"
        fi
    done <<< "$commands"
    printf "  %s╰────────────────────────────────────────────────────────────────────────╯%s\n\n" "$C_BOLD$C_CYAN" "$C_RESET"
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
# 2. Gestion et Avertissements Privilèges (Sudo vs Non-Sudo)
# ------------------------------------------------------------------------------
if [ "$IS_ROOT" -eq 1 ]; then
    printf "  %s✔%s %sPrivilèges : ${C_BOLD}${C_GREEN}root${C_RESET} %s(%s, accès complet au système hôte)%s\n" "$C_GREEN" "$C_RESET" "$C_WHITE" "$C_MUTED" "$OS_PRETTY" "$C_RESET"
else
    CAN_SUDO=0
    if command -v sudo >/dev/null 2>&1; then
        CAN_SUDO=1
    fi

    if [ "$USE_SUDO" = "1" ] && [ "$CAN_SUDO" -eq 1 ]; then
        if sudo -v 2>/dev/null; then
            SUDO_CMD="sudo"
            printf "  %s✔%s %sPrivilèges : ${C_BOLD}${C_GREEN}%s (mode sudo activé)${C_RESET} %s(%s)%s\n" "$C_GREEN" "$C_RESET" "$C_WHITE" "$CURRENT_USER" "$C_MUTED" "$OS_PRETTY" "$C_RESET"
        else
            printf "  %s!%s %sÉchec de l'authentification sudo. Bascule en mode applicatif utilisateur.%s\n" "$C_GOLD" "$C_RESET" "$C_MUTED" "$C_RESET"
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
            SUDO_OPTIONS+=("Activer le mode sudo (Recommandé VPS : Docker, UFW, SSH 22, systemd)")
        fi
        SUDO_OPTIONS+=(
            "Continuer en mode applicatif utilisateur (Docker sans modifier l'hôte)"
            "Afficher les conseils d'usage et les impacts de sécurité"
        )

        SHOW_EXPLANATION=1
        while [ "$SHOW_EXPLANATION" -eq 1 ]; do
            gum_choose "Gestion des privilèges d'installation" "${SUDO_OPTIONS[@]}" && SUDO_CHOICE=$? || SUDO_CHOICE=$?
            if [ "$CAN_SUDO" -eq 1 ]; then
                case "$SUDO_CHOICE" in
                    0)
                        if sudo -v; then
                            SUDO_CMD="sudo"
                            USE_SUDO="1"
                            SHOW_EXPLANATION=0
                            printf "  %s✔%s %sMode sudo activé avec succès.%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_GREEN" "$C_RESET"
                        else
                            printf "  %s✖%s %sMot de passe sudo invalide. Poursuite en mode applicatif.%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$C_RESET"
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
    # --------------------------------------------------------------------------
    # ÉTAPE SYSTÈME 1 : Docker & Dépendances
    # --------------------------------------------------------------------------
    if [ "$DOCKER_AVAILABLE" -eq 1 ]; then
        DOCKER_VER=$(docker --version 2>/dev/null | awk '{print $3}' | tr -d ',' || echo "ok")
        printf "  %s✔%s %sDocker est déjà installé et opérationnel : ${C_BOLD}${C_GREEN}Docker %s & Compose${C_RESET}\n" "$C_GREEN" "$C_RESET" "$C_WHITE" "$DOCKER_VER"
        STEP_DOCKER="skip"
    elif [ "$STEP_DOCKER" = "auto" ]; then
        DOCKER_MENU=(
            "[Exécution auto] Installer Docker CE officiel & Docker Compose (Recommandé)"
            "[Ignorer cette étape] Je gère l'installation de Docker moi-même"
            "[Afficher les commandes] Voir les commandes d'installation manuelles annotées"
        )
        gum_choose "Dépendances système & Docker CE" "${DOCKER_MENU[@]}" && DOCKER_SELECTION=$? || DOCKER_SELECTION=$?
        case "$DOCKER_SELECTION" in
            0) STEP_DOCKER="auto" ;;
            1) STEP_DOCKER="skip" ;;
            2)
                STEP_DOCKER="show"
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
                print_manual_commands_box "Commandes manuelles : Installation Docker CE & Compose" "$DOCKER_CMDS"
                ;;
        esac
    fi

    # --------------------------------------------------------------------------
    # ÉTAPE SYSTÈME 2 : Port SSH Git et Bascule OpenSSH
    # --------------------------------------------------------------------------
    if [ "$SSHD_ON_PORT_22" -eq 1 ]; then
        printf "\n  %s!%s %sLe port 22 est actuellement occupé par le serveur OpenSSH hôte (sshd).%s\n" "$C_GOLD" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
        SSHD_MENU=(
            "[Exécution auto] Basculer OpenSSH hôte vers le port 2222 (Port 22 réservé à Git)"
            "[Port alternatif] Conserver OpenSSH sur 22 et utiliser le port 2223 pour gickers.sh"
            "[Ignorer cette étape] Ne pas modifier la configuration OpenSSH"
            "[Afficher les commandes] Voir les commandes manuelles de migration de port OpenSSH"
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
            3)
                STEP_SSHD="show"
                SSHD_CMDS=$(cat << 'EOF'
# 1. Ouvrir le port 2222 dans le pare-feu AVANT de modifier sshd
sudo ufw allow 2222/tcp comment 'OpenSSH System (moved from 22)'
# 2. Configurer OpenSSH pour écouter sur 2222
sudo tee /etc/ssh/sshd_config.d/99-gickers-ssh-port.conf << 'CONF'
Port 2222
CONF
# 3. Redémarrer sshd ou le socket systemd
sudo systemctl daemon-reload && sudo systemctl restart ssh.socket || sudo systemctl restart sshd
# 4. Vos futures connexions d'administration VPS se feront avec :
# ssh -p 2222 user@<IP_VPS>
EOF
)
                print_manual_commands_box "Commandes manuelles : Migration OpenSSH vers port 2222" "$SSHD_CMDS"
                SSH_PORT="22"
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
    if [ "$UFW_ACTIVE" -eq 1 ]; then
        if [ "$STEP_FIREWALL" = "auto" ]; then
            UFW_MENU=(
                "[Exécution auto] Autoriser les ports Web (80, 443), Git ($SSH_PORT) et hôte dans UFW"
                "[Ignorer cette étape] Je gère mes règles de pare-feu moi-même"
                "[Afficher les commandes] Voir les règles de pare-feu UFW à appliquer manuellement"
            )
            gum_choose "Configuration du pare-feu hôte (UFW actif détecté)" "${UFW_MENU[@]}" && UFW_SELECTION=$? || UFW_SELECTION=$?
            case "$UFW_SELECTION" in
                0) STEP_FIREWALL="auto" ;;
                1) STEP_FIREWALL="skip" ;;
                2)
                    STEP_FIREWALL="show"
                    UFW_CMDS=$(cat << EOF
# 1. Autoriser le port SSH d'administration du système
sudo ufw allow 2222/tcp comment 'System OpenSSH'
# 2. Autoriser HTTP (80) et HTTPS (443) pour Caddy SSL
sudo ufw allow 80/tcp comment 'Gickers HTTP / ACME'
sudo ufw allow 443/tcp comment 'Gickers HTTPS'
# 3. Autoriser le port Git SSH public
sudo ufw allow ${SSH_PORT}/tcp comment 'Gickers Git SSH'
# 4. Activer le pare-feu
sudo ufw enable
EOF
)
                    print_manual_commands_box "Commandes manuelles : Règles de pare-feu UFW" "$UFW_CMDS"
                    ;;
            esac
        fi
    else
        printf "  %sℹ%s %sPare-feu hôte : UFW inactif ou non installé (étape pare-feu ignorée)%s\n" "$C_CYAN" "$C_RESET" "$C_MUTED" "$C_RESET"
        STEP_FIREWALL="skip"
    fi

    # --------------------------------------------------------------------------
    # ÉTAPE SYSTÈME 4 : Service Systemd (gickers.service)
    # --------------------------------------------------------------------------
    if [ "$SYSTEMD_GICKERS_EXISTS" -eq 1 ]; then
        printf "  %s✔%s %sService systemd : ${C_BOLD}${C_GREEN}gickers.service déjà présent${C_RESET}\n" "$C_GREEN" "$C_RESET" "$C_WHITE"
        STEP_SYSTEMD="skip"
    elif [ "$STEP_SYSTEMD" = "auto" ]; then
        SYSTEMD_MENU=(
            "[Exécution auto] Créer et activer gickers.service pour redémarrage auto au boot"
            "[Ignorer cette étape] Gérer les conteneurs manuellement via docker compose"
            "[Afficher les commandes] Voir le fichier d'unité systemd et les commandes systemctl"
        )
        gum_choose "Intégration systemd (démarrage automatique au boot du VPS)" "${SYSTEMD_MENU[@]}" && SYSTEMD_SELECTION=$? || SYSTEMD_SELECTION=$?
        case "$SYSTEMD_SELECTION" in
            0) STEP_SYSTEMD="auto" ;;
            1) STEP_SYSTEMD="skip" ;;
            2)
                STEP_SYSTEMD="show"
                SYSTEMD_CMDS=$(cat << EOF
# 1. Créer le service systemd
sudo tee /etc/systemd/system/gickers.service << 'UNIT'
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
UNIT

# 2. Recharger et activer le service au démarrage
sudo systemctl daemon-reload
sudo systemctl enable gickers.service
EOF
)
                print_manual_commands_box "Commandes manuelles : Service systemd gickers.service" "$SYSTEMD_CMDS"
                ;;
        esac
    fi

    # --------------------------------------------------------------------------
    # Formulaire de configuration applicative
    # --------------------------------------------------------------------------
    # 0. Utilisateur système dédié et isolation
    USER_OPTS=()
    if [ "$IS_ROOT" -eq 1 ] || [ "$USE_SUDO" = "1" ]; then
        USER_OPTS+=(
            "[Recommandé] Créer ou utiliser l'utilisateur système dédié non-root (gickers)"
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
        "Standard (Ports 80 & 443 avec certificats Let's Encrypt automatiques par Caddy)"
        "Personnaliser les ports Web (ex: derrière un reverse-proxy existant)"
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

    # 6. Admin Username
    DEFAULT_ADMIN_USER="${ADMIN_USER:-admin}"
    gum_input "Nom d'utilisateur administrateur" ADMIN_USER "$DEFAULT_ADMIN_USER"
    ADMIN_USER=$(echo "$ADMIN_USER" | tr -d '[:space:]')

    # 7. Server Access Policy
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

    # 8. Summary & Confirmation
    printf "\n"
    printf "  %s%sConfiguration finale du déploiement :%s\n" "$C_BOLD" "$C_CYAN" "$C_RESET"
    printf "    • %sUtilisateur hôte :%s     %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_BOLD$C_GREEN" "$SYSTEM_USER" "$C_RESET"
    printf "    • %sDomaine public :%s       %shttps://%s%s\n" "$C_MUTED" "$C_RESET" "$C_BOLD$C_WHITE" "$DOMAIN" "$C_RESET"
    printf "    • %sNom d'usage :%s          %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_BOLD$C_WHITE" "$APP_NAME" "$C_RESET"
    printf "    • %sRépertoire cible :%s     %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_GOLD" "$INSTALL_DIR" "$C_RESET"
    printf "    • %sPort SSH Git :%s         %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_CYAN" "$SSH_PORT" "$C_RESET"
    printf "    • %sPorts Web :%s            %s%s / %s%s\n" "$C_MUTED" "$C_RESET" "$C_CYAN" "$HTTP_PORT" "$HTTPS_PORT" "$C_RESET"
    printf "    • %sAdmin initial :%s        %s@%s%s\n" "$C_MUTED" "$C_RESET" "$C_GREEN" "$ADMIN_USER" "$C_RESET"
    printf "    • %sÉtape Docker :%s         %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_WHITE" "$STEP_DOCKER" "$C_RESET"
    printf "    • %sBascule SSHD :%s         %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_WHITE" "$STEP_SSHD" "$C_RESET"
    printf "    • %sPare-feu UFW :%s         %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_WHITE" "$STEP_FIREWALL" "$C_RESET"
    printf "    • %sService Systemd :%s      %s%s%s\n" "$C_MUTED" "$C_RESET" "$C_WHITE" "$STEP_SYSTEMD" "$C_RESET"
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
            $EXEC_SUDO useradd -m -s /bin/bash -U "$SYSTEM_USER" >/dev/null 2>&1 || true
        ) &
        gum_spin $! "Création de l'utilisateur système dédié ($SYSTEM_USER)..."
    fi
fi

if [ "$STEP_DOCKER" = "auto" ] && [ -n "$EXEC_SUDO" -o "$IS_ROOT" -eq 1 ]; then
    if command -v apt-get >/dev/null 2>&1; then
        (
            $EXEC_SUDO apt-get update -y >/dev/null 2>&1
            $EXEC_SUDO apt-get install -y --no-install-recommends \
                apt-transport-https ca-certificates curl gnupg git git-lfs jq ufw fail2ban openssl tar >/dev/null 2>&1
        ) &
        gum_spin $! "Vérification et installation des paquets système (apt)..."
    fi

    if ! command -v docker >/dev/null 2>&1; then
        if [ -d /etc/apt ]; then
            (
                $EXEC_SUDO install -m 0755 -d /etc/apt/keyrings
                curl -fsSL "https://download.docker.com/linux/$OS_NAME/gpg" | $EXEC_SUDO gpg --dearmor -o /etc/apt/keyrings/docker.gpg --yes >/dev/null 2>&1
                $EXEC_SUDO chmod a+r /etc/apt/keyrings/docker.gpg
                CODENAME=$(. /etc/os-release 2>/dev/null && echo "$VERSION_CODENAME" || echo "stable")
                [ -z "$CODENAME" ] && CODENAME="stable"
                echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/$OS_NAME $CODENAME stable" \
                    | $EXEC_SUDO tee /etc/apt/sources.list.d/docker.list >/dev/null
                $EXEC_SUDO apt-get update -y >/dev/null 2>&1
                $EXEC_SUDO apt-get install -y --no-install-recommends docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin >/dev/null 2>&1
                $EXEC_SUDO systemctl enable --now docker >/dev/null 2>&1 || true
            ) &
            gum_spin $! "Installation officielle de Docker CE & Docker Compose..."
        fi
    fi

    if [ "$SYSTEM_USER" != "root" ] && id -u "$SYSTEM_USER" >/dev/null 2>&1; then
        $EXEC_SUDO usermod -aG docker "$SYSTEM_USER" 2>/dev/null || true
    fi
    if [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != "root" ]; then
        $EXEC_SUDO usermod -aG docker "$SUDO_USER" 2>/dev/null || true
    elif [ "$IS_ROOT" -eq 0 ]; then
        $EXEC_SUDO usermod -aG docker "$CURRENT_USER" 2>/dev/null || true
    fi
fi

# B. Bascule OpenSSH hôte vers le port 2222
if [ "$STEP_SSHD" = "auto" ] && [ -n "$EXEC_SUDO" -o "$IS_ROOT" -eq 1 ]; then
    (
        if command -v ufw >/dev/null 2>&1; then
            $EXEC_SUDO ufw allow 2222/tcp comment 'OpenSSH System (moved from 22)' >/dev/null 2>&1 || true
        fi
        if [ -d /etc/ssh/sshd_config.d ]; then
            echo "Port 2222" | $EXEC_SUDO tee /etc/ssh/sshd_config.d/99-gickers-ssh-port.conf >/dev/null
            $EXEC_SUDO chmod 644 /etc/ssh/sshd_config.d/99-gickers-ssh-port.conf
        fi
        if [ -f /etc/ssh/sshd_config ]; then
            $EXEC_SUDO sed -i -E 's/^[#[:space:]]*Port[[:space:]]+22[[:space:]]*$/Port 2222/' /etc/ssh/sshd_config
        fi
        if systemctl is-active --quiet ssh.socket 2>/dev/null; then
            $EXEC_SUDO mkdir -p /etc/systemd/system/ssh.socket.d
            echo -e "[Socket]\nListenStream=\nListenStream=2222" | $EXEC_SUDO tee /etc/systemd/system/ssh.socket.d/listen-2222.conf >/dev/null
            $EXEC_SUDO systemctl daemon-reload >/dev/null 2>&1
            $EXEC_SUDO systemctl restart ssh.socket >/dev/null 2>&1 || true
        else
            $EXEC_SUDO systemctl restart ssh >/dev/null 2>&1 || $EXEC_SUDO systemctl restart sshd >/dev/null 2>&1 || true
        fi
    ) &
    gum_spin $! "Bascule sécurisée de OpenSSH hôte vers le port 2222 (port 22 libéré)..."

    printf "\n  %s!%s %s[IMPORTANT] OpenSSH hôte écoute désormais sur le port 2222 :%s\n" "$C_GOLD" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
    printf "    %sssh -p 2222 $(whoami)@<IP_VPS>%s\n\n" "$C_CYAN" "$C_RESET"
fi

# C. Règles de pare-feu UFW
if [ "$STEP_FIREWALL" = "auto" ] && [ -n "$EXEC_SUDO" -o "$IS_ROOT" -eq 1 ]; then
    if command -v ufw >/dev/null 2>&1; then
        (
            CURRENT_SSH=$(ss -tlnp 2>/dev/null | grep -E 'sshd|ssh' | awk '{print $4}' | awk -F: '{print $NF}' | head -n 1 || echo "2222")
            [ -z "$CURRENT_SSH" ] && CURRENT_SSH="2222"
            $EXEC_SUDO ufw allow "$CURRENT_SSH"/tcp comment 'Host SSH daemon' >/dev/null 2>&1 || true
            $EXEC_SUDO ufw allow "$HTTP_PORT"/tcp comment 'Gickers HTTP' >/dev/null 2>&1 || true
            $EXEC_SUDO ufw allow "$HTTPS_PORT"/tcp comment 'Gickers HTTPS' >/dev/null 2>&1 || true
            if [ "$SSH_PORT" != "$CURRENT_SSH" ]; then
                $EXEC_SUDO ufw allow "$SSH_PORT"/tcp comment 'Gickers Git SSH' >/dev/null 2>&1 || true
            fi
            $EXEC_SUDO ufw --force enable >/dev/null 2>&1 || true
        ) &
        gum_spin $! "Configuration des règles de pare-feu UFW..."
    fi
fi

# ------------------------------------------------------------------------------
# 5. Répertoire Applicatif et Synchronisation du Projet
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
            git clone --depth 1 https://github.com/gickerssh/gickers.sh.git "$INSTALL_DIR" >/dev/null 2>&1 || git clone --depth 1 https://github.com/gickerssh/gickers.sh.git . >/dev/null 2>&1 || true
        ) &
        gum_spin $! "Téléchargement du projet gickers.sh..."
    fi
fi

mkdir -p .data .data/caddy/sites var var/cache var/log
chmod 755 .data .data/caddy/sites var 2>/dev/null || true

# ------------------------------------------------------------------------------
# 6. Configuration de Production (.env.docker)
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
# 7. Build et Déploiement Docker Compose
# ------------------------------------------------------------------------------
( docker compose --env-file .env.docker build >/dev/null 2>&1 ) &
gum_spin $! "Construction des conteneurs Docker (PHP, gickers-ssh, FrankenPHP)..."

( docker compose --env-file .env.docker up -d postgres >/dev/null 2>&1 ) &
gum_spin $! "Démarrage de PostgreSQL..."

# Wait for PostgreSQL readiness
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
# 8. Service Systemd (gickers.service)
# ------------------------------------------------------------------------------
if [ "$STEP_SYSTEMD" = "auto" ] && [ -n "$EXEC_SUDO" -o "$IS_ROOT" -eq 1 ]; then
    SYSTEMD_USER_DIRECTIVE=""
    if [ "$SYSTEM_USER" != "root" ] && id -u "$SYSTEM_USER" >/dev/null 2>&1; then
        SYSTEMD_USER_DIRECTIVE="User=${SYSTEM_USER}
Group=${SYSTEM_USER}"
    fi

    (
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

        $EXEC_SUDO systemctl daemon-reload >/dev/null 2>&1 || true
        $EXEC_SUDO systemctl enable gickers.service >/dev/null 2>&1 || true
    ) &
    gum_spin $! "Activation du service systemd gickers.service..."

    if [ "$SYSTEM_USER" != "root" ] && id -u "$SYSTEM_USER" >/dev/null 2>&1; then
        $EXEC_SUDO chown -R "$SYSTEM_USER":"$SYSTEM_USER" "$INSTALL_DIR" 2>/dev/null || true
    elif [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != "root" ]; then
        $EXEC_SUDO chown -R "$SUDO_USER":"$SUDO_USER" "$INSTALL_DIR" 2>/dev/null || true
    fi
fi

# ------------------------------------------------------------------------------
# 9. Affichage de la Carte de Succès
# ------------------------------------------------------------------------------
sleep 2
echo ""
cat << 'EOF'
[38;2;65;72;104m[48;2;31;35;53m╭────────────────────────────────────────────────────────────────────────╮[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;158;206;106m[38;2;158;206;106m✓ Installation et déploiement de Gickers.sh terminés avec succès ![m[48;2;31;35;53m[m[48;2;31;35;53m[m[48;2;31;35;53m    [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;86;95;137m[38;2;86;95;137mVotre forge Git et plateforme de conteneurs est opérationnelle :[m[48;2;31;35;53m[m[48;2;31;35;53m[m[48;2;31;35;53m      [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m    • Interface Web : https://codes.sh[m[48;2;31;35;53m                                [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m    • Clone SSH : git clone ssh://codes.sh/<user>/<repo>.git[m[48;2;31;35;53m          [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m    • Clone HTTP : https://codes.sh/<user>/<repo>.git[m[48;2;31;35;53m                 [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m    • Accès CLI / TUI : ssh codes.sh[m[48;2;31;35;53m                                  [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m    • Info serveur : ssh codes.sh info[m[48;2;31;35;53m                                [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m╰────────────────────────────────────────────────────────────────────────╯[m
EOF
echo ""
