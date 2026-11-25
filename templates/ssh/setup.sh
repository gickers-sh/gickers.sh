#!/usr/bin/env bash
# ==============================================================================
# codes.sh SSH Configuration & Onboarding Wizard
# Inspired by Charmbracelet Gum (TokyoNight High Contrast Theme)
# Generated for @__USERNAME__ on __HOST__
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
            *) return 0 ;;
        esac
    fi

    printf "\n  %s?%s %s%s%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_WHITE" "$prompt_title" "$C_RESET"

    draw_items() {
        for i in "${!options[@]}"; do
            if [ "$i" -eq "$selected" ]; then
                printf "    ${BG_SELECT} %s❯ %s%s %s\n" "$C_PINK" "$C_BOLD$C_CYAN" "${options[i]}" "$C_RESET"
            else
                printf "      %s%s%s\n" "$C_MUTED" "${options[i]}" "$C_RESET"
            fi
        done
    }

    printf "${ESC}[?25l"
    draw_items

    while true; do
        IFS= read -rsn1 key < "$TTY_INPUT" || break
        if [[ $key == $'\x1b' ]]; then
            read -rsn2 -t 0.1 rest < "$TTY_INPUT" || true
            case "$rest" in
                "[A"|"k")
                    selected=$(( (selected - 1 + count) % count ))
                    printf "${ESC}[%dA" "$count"
                    draw_items
                    ;;
                "[B"|"j")
                    selected=$(( (selected + 1) % count ))
                    printf "${ESC}[%dA" "$count"
                    draw_items
                    ;;
            esac
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
__HEADER_CARD__
EOF

HOST="__HOST__"
USERNAME="__USERNAME__"
PORT_OPT="__PORT_OPT__"
PORT_LINE="__PORT_LINE__"
DEFAULT_KEY="__DEFAULT_KEY_PATH__"
KEY_PATH="$DEFAULT_KEY"
CONNECTED_KEY_DATA="__CONNECTED_KEY_DATA__"
CONNECTED_FINGERPRINT="__CONNECTED_FINGERPRINT__"
TEST_CMD="__TEST_CMD__"
IS_SIGNUP="__IS_SIGNUP__"
ALREADY_REGISTERED="__ALREADY_REGISTERED__"

if [ "$ALREADY_REGISTERED" = "0" ]; then
    DEFAULT_USER="${USERNAME:-$(whoami 2>/dev/null || echo "user")}"
    if [ -z "$USERNAME" ]; then
        while true; do
            gum_input "Choisissez votre nom d'utilisateur codes.sh" USERNAME "$DEFAULT_USER"
            USERNAME=$(echo "$USERNAME" | tr -d '[:space:]')
            if [[ ! "$USERNAME" =~ ^[a-zA-Z0-9._-]{2,32}$ ]]; then
                printf "  %s✖%s %sNom d'utilisateur invalide (2 à 32 caractères alphanumériques, '-' ou '_'). Réessayez :%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$C_RESET"
                continue
            fi
            break
        done
    else
        printf "  %s✔%s %sNom d'utilisateur sélectionné%s : ${BG_BADGE} ${C_BOLD}${C_CYAN}@%s ${C_RESET}\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET" "$USERNAME"
    fi
    DEFAULT_KEY="$HOME/.ssh/codes_sh_${USERNAME}"
    KEY_PATH="$DEFAULT_KEY"
fi

# Local detection of public key matching this session
DETECTED_KEY_FILE=""
if [ -n "$CONNECTED_KEY_DATA" ] && [ -d "$HOME/.ssh" ]; then
    for pub in "$HOME"/.ssh/*.pub; do
        [ -f "$pub" ] || continue
        if grep -F -q "$CONNECTED_KEY_DATA" "$pub" 2>/dev/null; then
            priv="${pub%.pub}"
            if [ -f "$priv" ]; then
                DETECTED_KEY_FILE="$priv"
                break
            fi
        fi
    done
fi

if [ -n "$DETECTED_KEY_FILE" ]; then
    OPT_ACTIVE="Utiliser la clé active de cette session (Recommandé: ${DETECTED_KEY_FILE})"
elif [ -n "$CONNECTED_FINGERPRINT" ]; then
    OPT_ACTIVE="Utiliser la clé active de cette session (Empreinte: ${CONNECTED_FINGERPRINT})"
else
    OPT_ACTIVE="Utiliser la clé active de cette session"
fi

KEY_OPTIONS=(
    "$OPT_ACTIVE"
    "Générer une nouvelle clé dédiée (${DEFAULT_KEY})"
    "Coller le contenu d'une clé publique existante (.pub)"
    "Conserver la clé par défaut du trousseau (agent global)"
)

gum_choose "Gestion de votre clé SSH" "${KEY_OPTIONS[@]}" && CHOICE_IDX=$? || CHOICE_IDX=$?

case "$CHOICE_IDX" in
    0)
        if [ -n "$DETECTED_KEY_FILE" ]; then
            KEY_PATH="$DETECTED_KEY_FILE"
        else
            gum_input "Chemin de votre clé privée active" KEY_PATH "$HOME/.ssh/id_ed25519"
        fi
        ;;
    1)
        ALGO_OPTIONS=(
            "ed25519 (moderne, recommandé)"
            "rsa (4096 bits, compatibilité entreprise)"
            "ed25519-sk (clé physique YubiKey / FIDO2)"
        )
        gum_choose "Type d'algorithme" "${ALGO_OPTIONS[@]}" && ALGO_IDX=$? || ALGO_IDX=$?
        case "$ALGO_IDX" in
            1) ALGO="rsa"; EXTRA_ARGS="-b 4096" ;;
            2) ALGO="ed25519-sk"; EXTRA_ARGS="" ;;
            *) ALGO="ed25519"; EXTRA_ARGS="" ;;
        esac

        OVERWRITE_KEY=1
        prompt_key_path_with_collision_check "$DEFAULT_KEY" KEY_PATH OVERWRITE_KEY

        mkdir -p "$(dirname "$KEY_PATH")" && chmod 700 "$(dirname "$KEY_PATH")"

        if [ "$OVERWRITE_KEY" -eq 1 ]; then
            KEY_COMMENT="${USERNAME}@$(hostname -s 2>/dev/null || echo "$HOST")"
            prompt_password_with_confirm "Passphrase" PASSPHRASE

            ( ssh-keygen -t "$ALGO" $EXTRA_ARGS -f "$KEY_PATH" -C "$KEY_COMMENT" -N "$PASSPHRASE" >/dev/null 2>&1 ) &
            gum_spin $! "Génération de la clé SSH $ALGO..."

            if [ -z "$PASSPHRASE" ]; then
                if [ "$(uname -s 2>/dev/null)" = "Darwin" ] && command -v ssh-add >/dev/null 2>&1; then
                    ssh-add --apple-use-keychain "$KEY_PATH" < /dev/null 2>/dev/null || ssh-add "$KEY_PATH" < /dev/null 2>/dev/null || true
                elif command -v ssh-add >/dev/null 2>&1; then
                    ssh-add "$KEY_PATH" < /dev/null 2>/dev/null || true
                fi
            fi

            # Register public key on codes.sh if already registered
            if [ -f "${KEY_PATH}.pub" ]; then
                PUB_CONTENT=$(tr -d '\r\n' < "${KEY_PATH}.pub")
                if [ "$ALREADY_REGISTERED" = "1" ]; then
                    if [ -n "$DETECTED_KEY_FILE" ]; then
                        ( ssh${PORT_OPT} -i "$DETECTED_KEY_FILE" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new "${USERNAME}@${HOST}" pubkey add "$PUB_CONTENT" >/dev/null 2>&1 ) &
                    else
                        ( ssh${PORT_OPT} -o IdentitiesOnly=no -o StrictHostKeyChecking=accept-new "${USERNAME}@${HOST}" pubkey add "$PUB_CONTENT" >/dev/null 2>&1 ) &
                    fi
                    gum_spin $! "Autorisation de la clé publique sur votre compte ${HOST}..." || true
                fi
            fi
        else
            printf "  %sℹ%s %sClé existante réutilisée : %s%s%s\n" "$C_CYAN" "$C_RESET" "$C_MUTED" "$C_GOLD" "$KEY_PATH" "$C_RESET"
        fi
        ;;
    2)
        # Paste a public key
        PASTED_KEY=""
        gum_paste_pubkey PASTED_KEY

        # Attempt to detect if private key already exists in ~/.ssh/
        KEY_DATA=$(echo "$PASTED_KEY" | awk '{print $2}')
        DETECTED_PRIV=""
        if [ -n "$KEY_DATA" ] && [ -d "$HOME/.ssh" ]; then
            for pub in "$HOME"/.ssh/*.pub; do
                [ -f "$pub" ] || continue
                if grep -F -q "$KEY_DATA" "$pub" 2>/dev/null; then
                    priv="${pub%.pub}"
                    if [ -f "$priv" ]; then
                        DETECTED_PRIV="$priv"
                        break
                    fi
                fi
            done
        fi

        if [ -n "$DETECTED_PRIV" ]; then
            KEY_PATH="$DETECTED_PRIV"
            printf "  %s✔%s %sClé privée locale associée%s : ${BG_BADGE} ${C_BOLD}${C_GOLD}%s ${C_RESET}\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET" "$KEY_PATH"
        else
            KEY_PATH=""
            printf "  %sℹ%s %sAuthentification configurée via votre agent SSH global%s\n" "$C_CYAN" "$C_RESET" "$C_MUTED" "$C_RESET"
        fi

        # Authorize the pasted public key on codes.sh if already registered
        if [ "$ALREADY_REGISTERED" = "1" ]; then
            if [ -n "$DETECTED_KEY_FILE" ]; then
                ( ssh${PORT_OPT} -i "$DETECTED_KEY_FILE" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new "${USERNAME}@${HOST}" pubkey add "$PASTED_KEY" >/dev/null 2>&1 ) &
            else
                ( ssh${PORT_OPT} -o IdentitiesOnly=no -o StrictHostKeyChecking=accept-new "${USERNAME}@${HOST}" pubkey add "$PASTED_KEY" >/dev/null 2>&1 ) &
            fi
            gum_spin $! "Autorisation de la clé collée sur votre compte ${HOST}..." || true
        fi
        ;;
    3)
        KEY_PATH=""
        ;;
esac

# Account registration on codes.sh for new signups
if [ "$ALREADY_REGISTERED" = "0" ]; then
    REG_SUCCESS=0
    KEY_OPT=""
    if [ "$CHOICE_IDX" -eq 1 ] && [ -f "${KEY_PATH}.pub" ]; then
        PUB_VAL=$(tr -d '\r\n' < "${KEY_PATH}.pub")
        KEY_OPT="-k \"$PUB_VAL\""
    elif [ "$CHOICE_IDX" -eq 2 ] && [ -n "$PASTED_KEY" ]; then
        KEY_OPT="-k \"$PASTED_KEY\""
    fi

    while [ "$REG_SUCCESS" -eq 0 ]; do
        TMP_ERR=$(mktemp 2>/dev/null || echo "/tmp/codes_reg_err_$$")
        if [ -n "$KEY_OPT" ]; then
            REG_CMD="ssh${PORT_OPT} -o IdentitiesOnly=no -o StrictHostKeyChecking=accept-new \"${HOST}\" signup \"${USERNAME}\" --register ${KEY_OPT}"
        elif [ -n "$KEY_PATH" ] && [ -f "$KEY_PATH" ]; then
            REG_CMD="ssh${PORT_OPT} -i \"$KEY_PATH\" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new \"${HOST}\" signup \"${USERNAME}\" --register"
        else
            REG_CMD="ssh${PORT_OPT} -o IdentitiesOnly=no -o StrictHostKeyChecking=accept-new \"${HOST}\" signup \"${USERNAME}\" --register"
        fi

        ( eval "$REG_CMD" >"$TMP_ERR" 2>&1 ) &
        gum_spin $! "Enregistrement de votre compte @${USERNAME} sur ${HOST}..."
        wait $! && REG_STATUS=0 || REG_STATUS=$?

        if [ "$REG_STATUS" -eq 0 ]; then
            REG_SUCCESS=1
            printf "  %s✔%s %sCompte @%s créé avec succès sur %s !%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$USERNAME" "$HOST" "$C_RESET"
        else
            ERR_MSG=$(cat "$TMP_ERR" 2>/dev/null || echo "Erreur d'enregistrement")
            printf "\n  %s✖%s %sÉchec de l'enregistrement : %s%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$ERR_MSG" "$C_RESET"
            printf "  %s!%s %sLe nom d'utilisateur @%s est peut-être déjà pris.%s\n" "$C_GOLD" "$C_RESET" "$C_MUTED" "$USERNAME" "$C_RESET"
            gum_input "Choisissez un autre nom d'utilisateur" USERNAME ""
            USERNAME=$(echo "$USERNAME" | tr -d '[:space:]')
        fi
        rm -f "$TMP_ERR"
    done
    TEST_CMD="ssh${PORT_OPT} ${USERNAME}@${HOST} info"
fi

SSH_DIR="$HOME/.ssh"
CONFIG_FILE="$SSH_DIR/config"
mkdir -p "$SSH_DIR" && chmod 700 "$SSH_DIR"
touch "$CONFIG_FILE" && chmod 600 "$CONFIG_FILE"

is_target_host_line() {
    local l="$1"
    local th="$2"
    if [[ "$l" =~ ^[[:space:]]*Host[[:space:]]+ ]]; then
        local rest="${l#*Host}"
        local tokens=($rest)
        for t in "${tokens[@]}"; do
            IFS="," read -ra sub <<< "$t"
            for s in "${sub[@]}"; do
                if [ "$s" = "$th" ]; then
                    return 0
                fi
            done
        done
    fi
    return 1
}

# 1. Base Host block configuration & auto-completion of missing options
IS_PROD="__IS_PROD__"
if [ "$IS_PROD" = "1" ]; then
    OPT_KEYS=("IdentitiesOnly" "RequestTTY" "SendEnv" "ControlMaster" "ControlPath" "ControlPersist" "ServerAliveInterval" "ServerAliveCountMax")
    OPT_LINES=(
        "    IdentitiesOnly yes"
        "    RequestTTY auto"
        "    SendEnv COLUMNS LINES TERM COLORTERM"
        "    ControlMaster auto"
        "    ControlPath ~/.ssh/cm-%r@%h:%p"
        "    ControlPersist 10m"
        "    ServerAliveInterval 30"
        "    ServerAliveCountMax 3"
    )
else
    OPT_KEYS=("IdentitiesOnly" "RequestTTY" "SendEnv" "ServerAliveInterval" "ServerAliveCountMax")
    OPT_LINES=(
        "    IdentitiesOnly yes"
        "    RequestTTY auto"
        "    SendEnv COLUMNS LINES TERM COLORTERM"
        "    ServerAliveInterval 30"
        "    ServerAliveCountMax 3"
    )
fi

TARGET_HOST_EXISTS=0
IN_TARGET_BLOCK=0
FOUND_KEYS=()
FOUND_CONTROL_LINES=0
SENDENV_NEEDS_UPDATE=0

while IFS= read -r line || [ -n "$line" ]; do
    if [[ "$line" =~ ^[[:space:]]*# ]]; then
        continue
    fi
    if is_target_host_line "$line" "$HOST"; then
        TARGET_HOST_EXISTS=1
        IN_TARGET_BLOCK=1
        continue
    fi
    if [ "$IN_TARGET_BLOCK" -eq 1 ]; then
        if [[ "$line" =~ ^[[:space:]]*(Host|Match)[[:space:]]+ ]]; then
            IN_TARGET_BLOCK=0
            continue
        fi
        if [[ "$line" =~ ^[[:space:]]*(ControlMaster|ControlPath|ControlPersist)[[:space:]]+ ]]; then
            FOUND_CONTROL_LINES=1
        fi
        if [[ "$line" =~ ^[[:space:]]*SendEnv[[:space:]]+.*COLUMNS ]] && [[ ! "$line" =~ COLORTERM ]]; then
            SENDENV_NEEDS_UPDATE=1
        fi
        for k in "${OPT_KEYS[@]}"; do
            if [ "$k" = "SendEnv" ]; then
                if [[ "$line" =~ ^[[:space:]]*SendEnv[[:space:]]+.*COLUMNS ]] && [[ "$line" =~ TERM ]] && [[ "$line" =~ COLORTERM ]]; then
                    FOUND_KEYS+=("$k")
                fi
            elif [[ "$line" =~ ^[[:space:]]*${k}[[:space:]]+ ]]; then
                FOUND_KEYS+=("$k")
            fi
        done
    fi
done < "$CONFIG_FILE"

if [ "$TARGET_HOST_EXISTS" -eq 0 ]; then
    if [ "$IS_PROD" = "1" ]; then
        cat << EOF >> "$CONFIG_FILE"

# Base configuration for __HOST__
Host __HOST__
    HostName __HOST__
__PORT_LINE__    IdentitiesOnly yes
    RequestTTY auto
    SendEnv COLUMNS LINES TERM COLORTERM
    ControlMaster auto
    ControlPath ~/.ssh/cm-%r@%h:%p
    ControlPersist 10m
    ServerAliveInterval 30
    ServerAliveCountMax 3
EOF
    else
        cat << EOF >> "$CONFIG_FILE"

# Base configuration for __HOST__
Host __HOST__
    HostName __HOST__
__PORT_LINE__    IdentitiesOnly yes
    RequestTTY auto
    SendEnv COLUMNS LINES TERM COLORTERM
    ServerAliveInterval 30
    ServerAliveCountMax 3
EOF
    fi
    printf "  %s✔%s %sConfiguration SSH %s initialisée%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$CONFIG_FILE" "$C_RESET"
else
    MISSING_LINES=()
    for i in "${!OPT_KEYS[@]}"; do
        key="${OPT_KEYS[i]}"
        exists=0
        for fk in "${FOUND_KEYS[@]}"; do
            if [ "$fk" == "$key" ]; then
                exists=1
                break
            fi
        done
        if [ "$exists" -eq 0 ]; then
            MISSING_LINES+=("${OPT_LINES[i]}")
        fi
    done

    NEEDS_CONFIG_UPDATE=0
    if [ ${#MISSING_LINES[@]} -gt 0 ] || [ "$SENDENV_NEEDS_UPDATE" -eq 1 ]; then
        NEEDS_CONFIG_UPDATE=1
    fi
    if [ "$IS_PROD" != "1" ] && [ "$FOUND_CONTROL_LINES" -eq 1 ]; then
        NEEDS_CONFIG_UPDATE=1
    fi

    if [ "$NEEDS_CONFIG_UPDATE" -eq 1 ]; then
        TMP_FILE="${CONFIG_FILE}.tmp.$$"
        INSERTED=0
        IN_TARGET=0
        while IFS= read -r line || [ -n "$line" ]; do
            if is_target_host_line "$line" "$HOST"; then
                IN_TARGET=1
            elif [ "$IN_TARGET" -eq 1 ] && [[ "$line" =~ ^[[:space:]]*(Host|Match)[[:space:]]+ ]]; then
                IN_TARGET=0
            fi

            if [ "$IN_TARGET" -eq 1 ] && [ "$IS_PROD" != "1" ]; then
                if [[ "$line" =~ ^[[:space:]]*(ControlMaster|ControlPath|ControlPersist)[[:space:]]+ ]]; then
                    continue
                fi
            fi

            if [ "$IN_TARGET" -eq 1 ] && [[ "$line" =~ ^[[:space:]]*SendEnv[[:space:]]+.*COLUMNS ]] && [[ ! "$line" =~ COLORTERM ]]; then
                line="    SendEnv COLUMNS LINES TERM COLORTERM"
            fi

            printf "%s\n" "$line" >> "$TMP_FILE"
            if [ "$INSERTED" -eq 0 ] && [[ ! "$line" =~ ^[[:space:]]*# ]] && is_target_host_line "$line" "$HOST"; then
                for m in "${MISSING_LINES[@]}"; do
                    printf "%s\n" "$m" >> "$TMP_FILE"
                done
                INSERTED=1
            fi
        done < "$CONFIG_FILE"
        mv "$TMP_FILE" "$CONFIG_FILE"
        chmod 600 "$CONFIG_FILE"
        if [ "$IS_PROD" != "1" ] && [ "$FOUND_CONTROL_LINES" -eq 1 ]; then
            printf "  %s✔%s %sConfiguration SSH %s optimisée%s %s(options ControlMaster purgées en dev)%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$CONFIG_FILE" "$C_RESET" "$C_MUTED" "$C_RESET"
        else
            printf "  %s✔%s %sConfiguration SSH %s optimisée%s %s(%d option(s) ajoutée(s))%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$CONFIG_FILE" "$C_RESET" "$C_MUTED" "${#MISSING_LINES[@]}" "$C_RESET"
        fi
    else
        printf "  %s✔%s %sConfiguration SSH %s déjà optimisée%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$CONFIG_FILE" "$C_RESET"
    fi
fi

# 2. Account profile for @$USERNAME
if [ -n "$KEY_PATH" ]; then
    HAS_MATCH=0
    ACTIVE_CONFIG=$(grep -v '^[[:space:]]*#' "$CONFIG_FILE" 2>/dev/null || true)
    if echo "$ACTIVE_CONFIG" | grep -E "^[[:space:]]*Match[[:space:]]+" | grep -E "\bhost[[:space:]]+[^\n]*\b${HOST}\b" | grep -Eq "\buser[[:space:]]+[^\n]*\b${USERNAME}\b"; then
        HAS_MATCH=1
    fi

    if [ "$HAS_MATCH" -eq 0 ]; then
        cat << EOF >> "$CONFIG_FILE"

# Account profile for @${USERNAME}
Match host ${HOST} user ${USERNAME}
    IdentityFile $KEY_PATH
EOF
        printf "  %s✔%s %sProfil utilisateur @%s lié à %s%s%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "${USERNAME}" "$C_GOLD" "$KEY_PATH" "$C_RESET"
    else
        IN_MATCH_BLOCK=0
        HAS_IDENTITY=0
        while IFS= read -r line || [ -n "$line" ]; do
            if [[ "$line" =~ ^[[:space:]]*# ]]; then continue; fi
            if [[ "$line" =~ ^[[:space:]]*Match[[:space:]]+ ]] && [[ "$line" =~ \bhost[[:space:]]+[^\n]*\b${HOST}\b ]] && [[ "$line" =~ \buser[[:space:]]+[^\n]*\b${USERNAME}\b ]]; then
                IN_MATCH_BLOCK=1
                continue
            fi
            if [ "$IN_MATCH_BLOCK" -eq 1 ]; then
                if [[ "$line" =~ ^[[:space:]]*(Host|Match)[[:space:]]+ ]]; then
                    IN_MATCH_BLOCK=0
                    continue
                fi
                if [[ "$line" =~ ^[[:space:]]*IdentityFile[[:space:]]+ ]]; then
                    HAS_IDENTITY=1
                    break
                fi
            fi
        done < "$CONFIG_FILE"

        if [ "$HAS_IDENTITY" -eq 0 ]; then
            TMP_FILE="${CONFIG_FILE}.tmp.$$"
            INSERTED=0
            while IFS= read -r line || [ -n "$line" ]; do
                printf "%s\n" "$line" >> "$TMP_FILE"
                if [ "$INSERTED" -eq 0 ] && [[ ! "$line" =~ ^[[:space:]]*# ]] && [[ "$line" =~ ^[[:space:]]*Match[[:space:]]+ ]] && [[ "$line" =~ \bhost[[:space:]]+[^\n]*\b${HOST}\b ]] && [[ "$line" =~ \buser[[:space:]]+[^\n]*\b${USERNAME}\b ]]; then
                    printf "    IdentityFile %s\n" "$KEY_PATH" >> "$TMP_FILE"
                    INSERTED=1
                fi
            done < "$CONFIG_FILE"
            mv "$TMP_FILE" "$CONFIG_FILE"
            chmod 600 "$CONFIG_FILE"
            printf "  %s✔%s %sIdentityFile lié à %s%s%s ajouté au profil @%s%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$C_GOLD" "$KEY_PATH" "$C_WHITE" "${USERNAME}" "$C_RESET"
        else
            printf "  %s✔%s %sProfil utilisateur @%s déjà configuré%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "${USERNAME}" "$C_RESET"
        fi
    fi
fi

# 3. Shell integration (export COLUMNS LINES for SendEnv)
SHELL_RC=""
CURRENT_SHELL=$(basename "${SHELL:-bash}")
if [ "$CURRENT_SHELL" = "zsh" ] && [ -f "$HOME/.zshrc" ]; then
    SHELL_RC="$HOME/.zshrc"
elif [ "$CURRENT_SHELL" = "bash" ]; then
    if [ -f "$HOME/.bashrc" ]; then
        SHELL_RC="$HOME/.bashrc"
    elif [ -f "$HOME/.bash_profile" ]; then
        SHELL_RC="$HOME/.bash_profile"
    fi
fi
if [ -z "$SHELL_RC" ]; then
    if [ -f "$HOME/.zshrc" ]; then
        SHELL_RC="$HOME/.zshrc"
    elif [ -f "$HOME/.bashrc" ]; then
        SHELL_RC="$HOME/.bashrc"
    fi
fi

if [ -n "$SHELL_RC" ]; then
    if grep -Eq '^[[:space:]]*export[[:space:]]+.*COLUMNS' "$SHELL_RC" 2>/dev/null; then
        printf "  %s✔%s %sVariables COLUMNS/LINES déjà exportées dans %s%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$SHELL_RC" "$C_RESET"
    else
        printf "\n# Export terminal dimensions for SSH CLI tools (codes.sh SendEnv)\nexport COLUMNS LINES\n" >> "$SHELL_RC"
        printf "  %s✔%s %sExport automatique COLUMNS/LINES configuré dans %s%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$SHELL_RC" "$C_RESET"
    fi
fi
export COLUMNS LINES 2>/dev/null || true

# 4. Connection verification & refresh multiplexer master
ssh -O exit -o "ControlPath=~/.ssh/cm-%r@%h:%p" "$HOST" >/dev/null 2>&1 || true
rm -f "$HOME/.ssh/cm-"*"-${HOST}:"* "$HOME/.ssh/cm-"*"@${HOST}:"* 2>/dev/null || true

( $TEST_CMD >/dev/null 2>&1 ) &
gum_spin $! "Vérification de la connexion vers ${HOST}..." || true

# 5. [Facultatif] Initialisation du mot de passe de secours
SETUP_PW_CHOICE=0
gum_choose "Souhaitez-vous configurer un mot de passe de secours pour @$USERNAME ?" \
    "Ignorer pour le moment" \
    "Oui (Recommandé pour récupérer l'accès si vous perdez votre clé SSH)" || SETUP_PW_CHOICE=$?

if [ "$SETUP_PW_CHOICE" -eq 1 ]; then
    BACKUP_PW=""
    prompt_backup_password_with_confirm "Mot de passe de secours" BACKUP_PW
    if [ -n "$BACKUP_PW" ]; then
        if ssh $PORT_OPT "$USERNAME@$HOST" user password --stdin <<< "$BACKUP_PW" >/dev/null 2>&1; then
            printf "  %s✔%s %sMot de passe de secours configuré avec succès pour @%s%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$USERNAME" "$C_RESET"
            printf "    %sEn cas de perte de clé, restaurez votre compte avec : %sssh%s %s@%s recover%s\n" "$C_MUTED" "$C_CYAN" "$PORT_OPT" "$USERNAME" "$HOST" "$C_RESET"
        else
            printf "  %s!%s %sImpossible d'enregistrer le mot de passe via SSH%s\n" "$C_GOLD" "$C_RESET" "$C_MUTED" "$C_RESET"
        fi
    fi
fi

echo ""
cat << 'EOF'
__SUCCESS_CARD__
EOF
echo ""
