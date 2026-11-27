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
