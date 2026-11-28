#!/usr/bin/env bash
# ==============================================================================
# gickers.sh SSH Configuration & Onboarding Wizard
# Inspired by Charmbracelet Gum (TokyoNight High Contrast Theme)
# Generated for @__USERNAME__ on __HOST__
# ==============================================================================
set -euo pipefail

__GUM_LIB__

# Display Termstrap Header
cat << 'EOF'
__HEADER_CARD__
EOF

HOST="__HOST__"
USERNAME="__USERNAME__"
PORT_NUM="__PORT__"
PORT_OPT="__PORT_OPT__"
PORT_LINE="__PORT_LINE__"
SSH_PORT_ARGS=()
if [ "$PORT_NUM" != "22" ] && [ -n "$PORT_NUM" ]; then
    SSH_PORT_ARGS=(-p "$PORT_NUM")
fi
DEFAULT_KEY="__DEFAULT_KEY_PATH__"
KEY_PATH="$DEFAULT_KEY"
CONNECTED_KEY_DATA="__CONNECTED_KEY_DATA__"
CONNECTED_FINGERPRINT="__CONNECTED_FINGERPRINT__"
TEST_CMD="__TEST_CMD__"
IS_SIGNUP="__IS_SIGNUP__"
ALREADY_REGISTERED="__ALREADY_REGISTERED__"
CLAIM_TOKEN="__CLAIM_TOKEN__"

# State machine pour la configuration initiale (Username & Clé SSH avec retour ESC)
SIGNUP_STEP=1
ORIGINAL_USERNAME="${USERNAME:-}"

while [ "$SIGNUP_STEP" -le 2 ]; do
    case "$SIGNUP_STEP" in
        1)
            if [ "$ALREADY_REGISTERED" = "0" ]; then
                DEFAULT_USER="${USERNAME:-$(whoami 2>/dev/null || echo "user")}"
                if [ -z "$ORIGINAL_USERNAME" ]; then
                    while true; do
                        if ! gum_input "Choisissez votre nom d'utilisateur gickers.sh" USERNAME "$DEFAULT_USER"; then
                            printf "\n  %sℹ Opération annulée par l'utilisateur.%s\n\n" "$C_CYAN" "$C_RESET"
                            exit 0
                        fi
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
                DEFAULT_KEY="$HOME/.ssh/gickers_sh_${USERNAME}"
                KEY_PATH="$DEFAULT_KEY"
            fi
            SIGNUP_STEP=2
            ;;
        2)
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

            if ! gum_choose "Gestion de votre clé SSH" "${KEY_OPTIONS[@]}"; then
                # Touche ESC pressée : retour à l'étape 1 si nouveau compte et nom personnalisable
                if [ "$ALREADY_REGISTERED" = "0" ] && [ -z "$ORIGINAL_USERNAME" ]; then
                    printf "${ESC}[1A${ESC}[2K"
                    SIGNUP_STEP=1
                    continue
                fi
                continue
            fi
            CHOICE_IDX=$?

            case "$CHOICE_IDX" in
                0)
                    if [ -n "$DETECTED_KEY_FILE" ]; then
                        KEY_PATH="$DETECTED_KEY_FILE"
                    else
                        if ! gum_input "Chemin de votre clé privée active" KEY_PATH "$HOME/.ssh/id_ed25519"; then
                            printf "${ESC}[1A${ESC}[2K"
                            continue
                        fi
                    fi
                    ;;
                1)
                    ALGO_OPTIONS=(
                        "ed25519 (moderne, recommandé)"
                        "rsa (4096 bits, compatibilité entreprise)"
                        "ed25519-sk (clé physique YubiKey / FIDO2)"
                    )
                    if ! gum_choose "Type d'algorithme" "${ALGO_OPTIONS[@]}"; then
                        printf "${ESC}[1A${ESC}[2K"
                        continue
                    fi
                    ALGO_IDX=$?
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

                        GEN_LOG=$(mktemp 2>/dev/null || echo "/tmp/gickers_keygen_$$")
                        ( ssh-keygen -t "$ALGO" $EXTRA_ARGS -f "$KEY_PATH" -C "$KEY_COMMENT" -N "$PASSPHRASE" >"$GEN_LOG" 2>&1 ) &
                        gum_stream_spin $! "Génération de la clé SSH $ALGO..." "$GEN_LOG"
                        rm -f "$GEN_LOG" 2>/dev/null || true

                        if [ -z "$PASSPHRASE" ]; then
                            if [ "$(uname -s 2>/dev/null)" = "Darwin" ] && command -v ssh-add >/dev/null 2>&1; then
                                ssh-add --apple-use-keychain "$KEY_PATH" < /dev/null 2>/dev/null || ssh-add "$KEY_PATH" < /dev/null 2>/dev/null || true
                            elif command -v ssh-add >/dev/null 2>&1; then
                                ssh-add "$KEY_PATH" < /dev/null 2>/dev/null || true
                            fi
                        fi

                        # Register public key on gickers.sh if already registered
                        if [ -f "${KEY_PATH}.pub" ]; then
                            PUB_CONTENT=$(tr -d '\r\n' < "${KEY_PATH}.pub")
                            PUB_CONTENT_ESCAPED=$(printf '%s' "$PUB_CONTENT" | sed 's/[\\"]/\\&/g')
                            if [ "$ALREADY_REGISTERED" = "1" ]; then
                                REG_PUB_LOG=$(mktemp 2>/dev/null || echo "/tmp/gickers_pubadd_$$")
                                if [ -n "$DETECTED_KEY_FILE" ]; then
                                    ( ssh${PORT_OPT} -i "$DETECTED_KEY_FILE" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new "${USERNAME}@${HOST}" pubkey add "\"$PUB_CONTENT_ESCAPED\"" < /dev/null >"$REG_PUB_LOG" 2>&1 ) &
                                else
                                    ( ssh${PORT_OPT} -o IdentitiesOnly=no -o StrictHostKeyChecking=accept-new "${USERNAME}@${HOST}" pubkey add "\"$PUB_CONTENT_ESCAPED\"" < /dev/null >"$REG_PUB_LOG" 2>&1 ) &
                                fi
                                gum_stream_spin $! "Autorisation de la clé publique sur votre compte ${HOST}..." "$REG_PUB_LOG" || true
                                rm -f "$REG_PUB_LOG" 2>/dev/null || true
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

                    # Authorize the pasted public key on gickers.sh if already registered
                    if [ "$ALREADY_REGISTERED" = "1" ]; then
                        PASTED_KEY_ESCAPED=$(printf '%s' "$PASTED_KEY" | sed 's/[\\"]/\\&/g')
                        REG_PASTE_LOG=$(mktemp 2>/dev/null || echo "/tmp/gickers_pasteadd_$$")
                        if [ -n "$DETECTED_KEY_FILE" ]; then
                            ( ssh${PORT_OPT} -i "$DETECTED_KEY_FILE" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new "${USERNAME}@${HOST}" pubkey add "\"$PASTED_KEY_ESCAPED\"" < /dev/null >"$REG_PASTE_LOG" 2>&1 ) &
                        else
                            ( ssh${PORT_OPT} -o IdentitiesOnly=no -o StrictHostKeyChecking=accept-new "${USERNAME}@${HOST}" pubkey add "\"$PASTED_KEY_ESCAPED\"" < /dev/null >"$REG_PASTE_LOG" 2>&1 ) &
                        fi
                        gum_stream_spin $! "Autorisation de la clé collée sur votre compte ${HOST}..." "$REG_PASTE_LOG" || true
                        rm -f "$REG_PASTE_LOG" 2>/dev/null || true
                    fi
                    ;;
                3)
                    KEY_PATH=""
                    ;;
            esac
            break
            ;;
    esac
done

# Account registration on gickers.sh for new signups
if [ "$ALREADY_REGISTERED" = "0" ]; then
    REG_SUCCESS=0
    REG_KEY_ARGS=()
    if [ "$CHOICE_IDX" -eq 1 ] && [ -f "${KEY_PATH}.pub" ]; then
        PUB_VAL=$(tr -d '\r\n' < "${KEY_PATH}.pub")
        PUB_VAL_ESCAPED=$(printf '%s' "$PUB_VAL" | sed 's/[\\"]/\\&/g')
        REG_KEY_ARGS=(-k "\"$PUB_VAL_ESCAPED\"")
    elif [ "$CHOICE_IDX" -eq 2 ] && [ -n "$PASTED_KEY" ]; then
        PASTED_KEY_ESCAPED=$(printf '%s' "$PASTED_KEY" | sed 's/[\\"]/\\&/g')
        REG_KEY_ARGS=(-k "\"$PASTED_KEY_ESCAPED\"")
    fi

    while [ "$REG_SUCCESS" -eq 0 ]; do
        TMP_ERR=$(mktemp 2>/dev/null || echo "/tmp/gickers_reg_err_$$")
        EXTRA_TOKEN_ARGS=()
        if [ -n "$CLAIM_TOKEN" ]; then
            EXTRA_TOKEN_ARGS=(--token "$CLAIM_TOKEN")
        fi
        REG_ARGS=("${SSH_PORT_ARGS[@]}")
        if [ "${#REG_KEY_ARGS[@]}" -gt 0 ]; then
            REG_ARGS+=(-o IdentitiesOnly=no -o StrictHostKeyChecking=accept-new "$HOST" signup "$USERNAME" --register "${EXTRA_TOKEN_ARGS[@]}" "${REG_KEY_ARGS[@]}")
        elif [ -n "$KEY_PATH" ] && [ -f "$KEY_PATH" ]; then
            REG_ARGS+=(-i "$KEY_PATH" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new "$HOST" signup "$USERNAME" --register "${EXTRA_TOKEN_ARGS[@]}")
        else
            REG_ARGS+=(-o IdentitiesOnly=no -o StrictHostKeyChecking=accept-new "$HOST" signup "$USERNAME" --register "${EXTRA_TOKEN_ARGS[@]}")
        fi

        ( ssh "${REG_ARGS[@]}" < /dev/null >"$TMP_ERR" 2>&1 ) &
        REG_STATUS=0
        gum_stream_spin $! "Enregistrement de votre compte @${USERNAME} sur ${HOST}..." "$TMP_ERR" || REG_STATUS=$?

        if [ "$REG_STATUS" -eq 0 ]; then
            REG_SUCCESS=1
            printf "  %s✔%s %sCompte @%s créé avec succès sur %s !%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$USERNAME" "$HOST" "$C_RESET"
        else
            ERR_MSG=$(cat "$TMP_ERR" 2>/dev/null || echo "Erreur d'enregistrement")
            if echo "$ERR_MSG" | grep -qiE "already exists|déjà pris|already taken"; then
                printf "\n  %s✖%s %sLe nom d'utilisateur @%s est déjà utilisé sur ce serveur.%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_PINK" "$USERNAME" "$C_RESET"
                if ! gum_input "Choisissez un autre nom d'utilisateur" USERNAME ""; then
                    continue
                fi
                USERNAME=$(echo "$USERNAME" | tr -d '[:space:]')
            else
                handle_step_error "Enregistrement du compte @${USERNAME}" "$TMP_ERR" "$HOME" || true
            fi
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
        printf "\n# Export terminal dimensions for SSH CLI tools (gickers.sh SendEnv)\nexport COLUMNS LINES\n" >> "$SHELL_RC"
        printf "  %s✔%s %sExport automatique COLUMNS/LINES configuré dans %s%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$SHELL_RC" "$C_RESET"
    fi
fi
export COLUMNS LINES 2>/dev/null || true

# 4. Connection verification & refresh multiplexer master
ssh -O exit -o "ControlPath=~/.ssh/cm-%r@%h:%p" "$HOST" >/dev/null 2>&1 || true
rm -f "$HOME/.ssh/cm-"*"-${HOST}:"* "$HOME/.ssh/cm-"*"@${HOST}:"* 2>/dev/null || true

VERIF_LOG=$(mktemp 2>/dev/null || echo "/tmp/gickers_ssh_verif_$$")
while true; do
    : > "$VERIF_LOG"
    ( ssh "${SSH_PORT_ARGS[@]}" -v "${USERNAME}@${HOST}" info >> "$VERIF_LOG" 2>&1 ) < /dev/null &
    if gum_stream_spin $! "Vérification de la connexion vers ${HOST}..." "$VERIF_LOG"; then
        break
    fi
    if handle_step_error "Vérification de la connexion vers ${HOST}" "$VERIF_LOG" "$HOME"; then
        break
    fi
done
rm -f "$VERIF_LOG" 2>/dev/null || true

# 5. [Facultatif] Initialisation du mot de passe de secours
SETUP_PW_CHOICE=0
gum_choose "Souhaitez-vous configurer un mot de passe de secours pour @$USERNAME ?" \
    "Ignorer pour le moment" \
    "Oui (Recommandé pour récupérer l'accès si vous perdez votre clé SSH)" && SETUP_PW_CHOICE=$? || SETUP_PW_CHOICE=$?

if [ "$SETUP_PW_CHOICE" -eq 1 ]; then
    BACKUP_PW=""
    if prompt_backup_password_with_confirm "Mot de passe de secours" BACKUP_PW; then
        if [ -n "$BACKUP_PW" ]; then
            if ssh $PORT_OPT "$USERNAME@$HOST" user password --stdin <<< "$BACKUP_PW" >/dev/null 2>&1; then
                printf "  %s✔%s %sMot de passe de secours configuré avec succès pour @%s%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$USERNAME" "$C_RESET"
                printf "    %sEn cas de perte de clé, restaurez votre compte avec : %sssh%s %s@%s recover%s\n" "$C_MUTED" "$C_CYAN" "$PORT_OPT" "$USERNAME" "$HOST" "$C_RESET"
            else
                printf "  %s!%s %sImpossible d'enregistrer le mot de passe via SSH%s\n" "$C_GOLD" "$C_RESET" "$C_MUTED" "$C_RESET"
            fi
        fi
    fi
fi

echo ""
cat << 'EOF'
__SUCCESS_CARD__
EOF
echo ""
