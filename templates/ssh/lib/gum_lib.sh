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

    local term_cols=""
    if [ -n "$TTY_INPUT" ] && [ -r "$TTY_INPUT" ]; then
        term_cols=$(stty size < "$TTY_INPUT" 2>/dev/null | awk '{print $2}')
        [ -z "$term_cols" ] && term_cols=$(tput cols < "$TTY_INPUT" 2>/dev/null || true)
    fi
    [ -z "$term_cols" ] && term_cols=$(tput cols 2>/dev/null || echo 80)
    [ -z "$term_cols" ] || [ "$term_cols" -le 0 ] && term_cols=80
    local max_width=$(( term_cols - 6 ))
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

# Gum-styled checklist / multi-select component with checkboxes and preview info box
# Usage: gum_checklist "Titre" out_var "Label:checked[:description[:commands]]" ...
# Returns an array in $out_var containing 1 or 0 for each item.
gum_checklist() {
    local prompt_title="$1"
    local out_var="$2"
    shift 2

    local labels=()
    local checked=()
    local descs=()
    local cmds=()
    for item in "$@"; do
        local rest="$item"
        local l="${rest%%|*}"
        rest="${rest#*|}"
        local c="${rest%%|*}"
        rest="${rest#*|}"
        local d="${rest%%|*}"
        local cmd="${rest#*|}"
        labels+=("$l")
        checked+=("${c:-1}")
        descs+=("$d")
        cmds+=("$cmd")
    done

    local count=${#labels[@]}
    local selected=0
    local key=""

    # Fallback non-interactif
    if [ -z "$TTY_INPUT" ] || [ ! -t 1 ]; then
        local res_array=("${checked[@]}")
        eval "$out_var=(\"\${res_array[@]}\")"
        return 0
    fi

    local term_cols=""
    if [ -n "$TTY_INPUT" ] && [ -r "$TTY_INPUT" ]; then
        term_cols=$(stty size < "$TTY_INPUT" 2>/dev/null | awk '{print $2}')
        [ -z "$term_cols" ] && term_cols=$(tput cols < "$TTY_INPUT" 2>/dev/null || true)
    fi
    [ -z "$term_cols" ] && term_cols=$(tput cols 2>/dev/null || echo 80)
    [ -z "$term_cols" ] || [ "$term_cols" -le 0 ] && term_cols=80
    local max_width=$(( term_cols - 10 ))
    [ "$max_width" -lt 30 ] && max_width=30

    local box_width=$(( term_cols - 6 ))
    [ "$box_width" -gt 92 ] && box_width=92
    [ "$box_width" -lt 40 ] && box_width=40
    local inner_width=$(( box_width - 4 ))

    printf "\n  %s?%s %s%s%s %s(Espace: cocher/décocher • a: tout basculer • Entrée: valider)%s\n" \
        "$C_PINK" "$C_RESET" "$C_BOLD$C_WHITE" "$prompt_title" "$C_RESET" "$C_DIM" "$C_RESET"

    local last_rendered_lines=0

    # Fonction pour coloriser syntaxiquement une ligne de commande bash
    colorize_bash_line() {
        local raw_line="$1"
        if [[ "$raw_line" =~ ^[[:space:]]*# ]]; then
            printf "%s" "$C_DIM$raw_line$C_RESET"
            return
        fi

        # Mots-clés / utilitaires en vert/cyan, drapeaux en muted, connecteurs en rose, variables en or
        local colored=""
        local tokens=()
        read -ra tokens <<< "$raw_line"
        local first=1
        for token in "${tokens[@]}"; do
            local color="$C_GOLD"
            if [ "$first" -eq 1 ] || [ "$token" = "&&" ] || [ "$token" = "||" ] || [ "$token" = "|" ] || [ "$token" = ";" ]; then
                if [ "$token" = "&&" ] || [ "$token" = "||" ] || [ "$token" = "|" ] || [ "$token" = ";" ] || [ "$token" = "\\" ]; then
                    color="$C_PINK"
                    first=1
                else
                    color="$C_GREEN"
                    first=0
                fi
            elif [[ "$token" =~ ^- ]]; then
                color="$C_MUTED"
            elif [[ "$token" =~ ^\$ ]] || [[ "$token" =~ = ]]; then
                color="$C_CYAN"
            elif [[ "$token" =~ ^\> ]] || [[ "$token" =~ ^\< ]]; then
                color="$C_PINK"
            fi
            colored+="${color}${token}${C_RESET} "
        done
        printf "%s" "${colored% }"
    }

    draw_checklist_and_box() {
        local rendered=0
        for i in "${!labels[@]}"; do
            local opt_text="${labels[i]}"
            if [ "${#opt_text}" -gt "$max_width" ]; then
                opt_text="${opt_text:0:$((max_width - 3))}..."
            fi

            local box_icon
            if [ "${checked[i]}" -eq 1 ]; then
                box_icon="${C_GREEN}[✔]${C_RESET}"
            else
                box_icon="${C_DIM}[ ]${C_RESET}"
            fi

            if [ "$i" -eq "$selected" ]; then
                printf "\r${ESC}[2K    ${BG_SELECT} %s❯ %s %s%s %s\n" \
                    "$C_PINK" "$box_icon" "$C_BOLD$C_CYAN" "$opt_text" "$C_RESET"
            else
                printf "\r${ESC}[2K      %s %s%s%s\n" \
                    "$box_icon" "$C_MUTED" "$opt_text" "$C_RESET"
            fi
            rendered=$((rendered + 1))
        done

        # Affichage du bloc d'informations et des commandes de l'élément focus sans encadré
        local cur_desc="${descs[selected]:-}"
        local cur_cmd="${cmds[selected]:-}"

        if [ -n "$cur_desc" ] || [ -n "$cur_cmd" ]; then
            printf "\r${ESC}[2K\n"
            rendered=$((rendered + 1))

            if [ -n "$cur_desc" ]; then
                local desc_clean
                desc_clean=$(echo "$cur_desc" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')
                local first_dline=1
                while IFS= read -r dline; do
                    [ -z "$dline" ] && continue
                    while [ "${#dline}" -gt "$inner_width" ]; do
                        local split_idx="$inner_width"
                        local prefix="${dline:0:$inner_width}"
                        if [[ "$prefix" =~ ^(.*)[[:space:]]([^[:space:]]*)$ ]]; then
                            split_idx="${#BASH_REMATCH[1]}"
                        fi
                        [ "$split_idx" -le 0 ] && split_idx="$inner_width"
                        local chunk="${dline:0:$split_idx}"
                        if [ "$first_dline" -eq 1 ]; then
                            printf "\r${ESC}[2K      %sℹ%s %s%s%s\n" "$C_CYAN" "$C_RESET" "$C_MUTED" "$chunk" "$C_RESET"
                            first_dline=0
                        else
                            printf "\r${ESC}[2K        %s%s%s\n" "$C_MUTED" "$chunk" "$C_RESET"
                        fi
                        rendered=$((rendered + 1))
                        dline="${dline:$split_idx}"
                        dline=$(echo "$dline" | sed -e 's/^[[:space:]]*//')
                    done
                    if [ "$first_dline" -eq 1 ]; then
                        printf "\r${ESC}[2K      %sℹ%s %s%s%s\n" "$C_CYAN" "$C_RESET" "$C_MUTED" "$dline" "$C_RESET"
                        first_dline=0
                    else
                        printf "\r${ESC}[2K        %s%s%s\n" "$C_MUTED" "$dline" "$C_RESET"
                    fi
                    rendered=$((rendered + 1))
                done <<< "$desc_clean"
            fi

            if [ -n "$cur_cmd" ]; then
                printf "\r${ESC}[2K\n"
                rendered=$((rendered + 1))
                while IFS= read -r cline; do
                    local remaining="$cline"
                    while [ "${#remaining}" -gt "$inner_width" ]; do
                        local split_idx="$inner_width"
                        local prefix="${remaining:0:$inner_width}"
                        if [[ "$prefix" =~ ^(.*)[[:space:]]([^[:space:]]*)$ ]]; then
                            split_idx="${#BASH_REMATCH[1]}"
                        fi
                        [ "$split_idx" -le 0 ] && split_idx="$inner_width"

                        local part="${remaining:0:$split_idx}"
                        local colored_part
                        colored_part=$(colorize_bash_line "$part")
                        printf "\r${ESC}[2K        %s\n" "$colored_part"
                        rendered=$((rendered + 1))
                        remaining="${remaining:$split_idx}"
                        remaining=$(echo "$remaining" | sed -e 's/^[[:space:]]*//')
                        [ -n "$remaining" ] && remaining="  $remaining"
                    done
                    local colored_rem
                    colored_rem=$(colorize_bash_line "$remaining")
                    printf "\r${ESC}[2K        %s\n" "$colored_rem"
                    rendered=$((rendered + 1))
                done <<< "$cur_cmd"
            fi
        fi

        last_rendered_lines=$rendered
    }

    printf "${ESC}[?25l"
    draw_checklist_and_box

    while true; do
        IFS= read -rsn1 key < "$TTY_INPUT" || break
        if [[ $key == $'\x1b' ]]; then
            read -rsn2 -t 0.2 rest < "$TTY_INPUT" || true
            case "$rest" in
                "[A"|"OA")
                    selected=$(( (selected - 1 + count) % count ))
                    printf "\r${ESC}[%dA" "$last_rendered_lines"
                    draw_checklist_and_box
                    ;;
                "[B"|"OB")
                    selected=$(( (selected + 1) % count ))
                    printf "\r${ESC}[%dA" "$last_rendered_lines"
                    draw_checklist_and_box
                    ;;
            esac
        elif [[ $key == "k" ]]; then
            selected=$(( (selected - 1 + count) % count ))
            printf "\r${ESC}[%dA" "$last_rendered_lines"
            draw_checklist_and_box
        elif [[ $key == "j" ]]; then
            selected=$(( (selected + 1) % count ))
            printf "\r${ESC}[%dA" "$last_rendered_lines"
            draw_checklist_and_box
        elif [[ $key == " " ]]; then
            if [ "${checked[selected]}" -eq 1 ]; then
                checked[selected]=0
            else
                checked[selected]=1
            fi
            printf "\r${ESC}[%dA" "$last_rendered_lines"
            draw_checklist_and_box
        elif [[ $key == "a" ]]; then
            local all_on=1
            for c in "${checked[@]}"; do
                [ "$c" -eq 0 ] && all_on=0 && break
            done
            local target_state=$(( 1 - all_on ))
            for i in "${!checked[@]}"; do
                checked[i]=$target_state
            done
            printf "\r${ESC}[%dA" "$last_rendered_lines"
            draw_checklist_and_box
        elif [[ $key == "" ]]; then
            break
        elif [[ $key == "q" ]]; then
            printf "${ESC}[?25h\n"
            echo "Annulé."
            exit 0
        fi
    done

    # Effacement complet du menu et de la boîte de détails
    printf "\r${ESC}[%dA${ESC}[J" "$last_rendered_lines"
    printf "${ESC}[?25h"

    local selected_count=0
    for c in "${checked[@]}"; do
        [ "$c" -eq 1 ] && selected_count=$((selected_count + 1))
    done

    printf "  %s✔%s %s%s%s : ${BG_BADGE} ${C_BOLD}${C_CYAN}%d/%d étapes sélectionnées ${C_RESET}\n" \
        "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$prompt_title" "$C_RESET" "$selected_count" "$count"

    local res_array=("${checked[@]}")
    eval "$out_var=(\"\${res_array[@]}\")"
    return 0
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
    wait "$pid"
    local exit_code=$?
    printf "${ESC}[?25h"
    if [ "$exit_code" -eq 0 ]; then
        printf "\r${ESC}[2K  %s✔%s %s%s%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$msg" "$C_RESET"
    else
        printf "\r${ESC}[2K  %s✖%s %s%s%s %s(Échec, code %d)%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_WHITE" "$msg" "$C_RESET" "$C_DIM" "$exit_code" "$C_RESET"
    fi
    return "$exit_code"
}

# Backwards compatibility aliases
select_menu() { gum_choose "$@"; }
prompt() { gum_input "$1" "$2" "${3:-}"; }
prompt_secret() { gum_password "$1" "$2"; }
spin() { gum_spin "$@"; }
