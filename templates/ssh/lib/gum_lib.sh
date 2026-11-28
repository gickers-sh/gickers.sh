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
            read -rsn2 -t 0.05 rest < "$TTY_INPUT" || true
            if [ -z "$rest" ]; then
                # Touche ESC seule : signal retour (BACK = 255)
                local total_lines=$(( count + 1 ))
                printf "\r${ESC}[%dA${ESC}[J" "$total_lines"
                printf "${ESC}[?25h"
                return 255
            fi
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

# ==============================================================================
#  Syntax Highlighting & Command Formatter (Borderless & High-Contrast)
# ==============================================================================

# Token colorizer for bash commands syntax highlighting
colorize_token() {
    local token="$1"
    local is_cmd="$2"

    if [ "$token" = "&&" ] || [ "$token" = "||" ] || [ "$token" = "|" ] || [ "$token" = ";" ] || [ "$token" = "\\" ] || [ "$token" = ">" ] || [ "$token" = ">>" ] || [ "$token" = "<" ] || [ "$token" = "<<" ]; then
        printf "%s%s%s" "$C_PINK" "$token" "$C_RESET"
    elif [ "$token" = "sudo" ]; then
        printf "%s%s%s" "$C_PINK" "$token" "$C_RESET"
    elif [ "$is_cmd" -eq 1 ]; then
        printf "%s%s%s" "$C_GREEN" "$token" "$C_RESET"
    elif [[ "$token" =~ ^-[a-zA-Z0-9_-] ]] || [[ "$token" =~ ^-- ]]; then
        printf "%s%s%s" "$C_MUTED" "$token" "$C_RESET"
    elif [[ "$token" =~ ^\" ]] || [[ "$token" =~ ^\' ]]; then
        printf "%s%s%s" "$C_GOLD" "$token" "$C_RESET"
    elif [[ "$token" =~ ^\$ ]] || [[ "$token" =~ = ]]; then
        printf "%s%s%s" "$C_CYAN" "$token" "$C_RESET"
    elif [[ "$token" =~ ^(update|install|allow|enable|restart|compose|config-manager|daemon-reload|add-repo|start|stop|down|up|run|build|clone|status|reload)$ ]]; then
        printf "%s%s%s" "$C_CYAN" "$token" "$C_RESET"
    elif [[ "$token" =~ ^https?:// ]] || [[ "$token" =~ ^/ ]]; then
        printf "%s%s%s" "$C_WHITE" "$token" "$C_RESET"
    else
        printf "%s%s%s" "$C_WHITE" "$token" "$C_RESET"
    fi
}

colorize_bash_line() {
    local raw_line="$1"

    # Comment line
    if [[ "$raw_line" =~ ^[[:space:]]*# ]]; then
        printf "%s%s%s" "$C_DIM" "$raw_line" "$C_RESET"
        return
    fi

    local indent=""
    if [[ "$raw_line" =~ ^([[:space:]]+) ]]; then
        indent="${BASH_REMATCH[1]}"
    fi
    local trimmed="${raw_line#"${indent}"}"

    local result="${indent}"
    local first=1
    local in_quote=""
    local cur_token=""
    local len=${#trimmed}

    local i=0
    while [ $i -lt $len ]; do
        local ch="${trimmed:$i:1}"

        if [ "$ch" = '"' ] || [ "$ch" = "'" ]; then
            if [ -z "$in_quote" ]; then
                in_quote="$ch"
                cur_token+="$ch"
            elif [ "$in_quote" = "$ch" ]; then
                in_quote=""
                cur_token+="$ch"
            else
                cur_token+="$ch"
            fi
            i=$((i + 1))
            continue
        fi

        if [ -n "$in_quote" ]; then
            cur_token+="$ch"
            i=$((i + 1))
            continue
        fi

        if [[ "$ch" =~ [[:space:]] ]]; then
            if [ -n "$cur_token" ]; then
                result+="$(colorize_token "$cur_token" "$first")"
                if [ "$cur_token" = "&&" ] || [ "$cur_token" = "||" ] || [ "$cur_token" = "|" ] || [ "$cur_token" = ";" ] || [ "$cur_token" = "\\" ] || [ "$cur_token" = "sudo" ]; then
                    first=1
                elif [ "$first" -eq 1 ]; then
                    first=0
                fi
                cur_token=""
            fi
            result+="$ch"
            i=$((i + 1))
            continue
        fi

        cur_token+="$ch"
        i=$((i + 1))
    done

    if [ -n "$cur_token" ]; then
        result+="$(colorize_token "$cur_token" "$first")"
    fi

    printf "%s" "$result"
}

format_bash_commands() {
    local commands="$1"
    local base_indent="${2:-    }"
    local prev_continuation=0

    while IFS= read -r line; do
        if [[ "$line" =~ ^[[:space:]]*$ ]]; then
            printf "\n"
            prev_continuation=0
            continue
        fi

        if [[ "$line" =~ ^[[:space:]]*# ]]; then
            printf "%s%s\n" "$base_indent" "$(colorize_bash_line "$line")"
            prev_continuation=0
            continue
        fi

        local line_indent=""
        if [[ "$line" =~ ^([[:space:]]+) ]]; then
            line_indent="${BASH_REMATCH[1]}"
        fi

        local effective_indent="$base_indent$line_indent"
        if [ -z "$line_indent" ] && [ "$prev_continuation" -eq 1 ]; then
            effective_indent="${base_indent}  "
        fi

        local trimmed="${line#"${line_indent}"}"
        printf "%s%s\n" "$effective_indent" "$(colorize_bash_line "$trimmed")"

        if [[ "$trimmed" =~ (\\|&&|\|\||\|)[[:space:]]*$ ]]; then
            prev_continuation=1
        else
            prev_continuation=0
        fi
    done <<< "$commands"
}

# Helper to print annotated manual commands in terminal without framed box borders
print_manual_commands() {
    local title="$1"
    local commands="$2"
    local desc="${3:-}"

    printf "\n"
    printf "  %sℹ%s %s%s%s\n" "$C_CYAN" "$C_RESET" "$C_BOLD$C_WHITE" "$title" "$C_RESET"
    if [ -n "$desc" ]; then
        local term_cols=""
        if [ -n "$TTY_INPUT" ] && [ -r "$TTY_INPUT" ]; then
            term_cols=$(stty size < "$TTY_INPUT" 2>/dev/null | awk '{print $2}')
            [ -z "$term_cols" ] && term_cols=$(tput cols < "$TTY_INPUT" 2>/dev/null || true)
        fi
        [ -z "$term_cols" ] && term_cols=$(tput cols 2>/dev/null || echo 80)
        [ -z "$term_cols" ] || [ "$term_cols" -le 0 ] && term_cols=80
        local max_w=$(( term_cols - 8 ))
        [ "$max_w" -lt 40 ] && max_w=40

        local desc_clean
        desc_clean=$(echo "$desc" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')
        while IFS= read -r dline; do
            [ -z "$dline" ] && continue
            while [ "${#dline}" -gt "$max_w" ]; do
                local split_idx="$max_w"
                local prefix="${dline:0:$max_w}"
                if [[ "$prefix" =~ ^(.*)[[:space:]]([^[:space:]]*)$ ]]; then
                    split_idx="${#BASH_REMATCH[1]}"
                fi
                [ "$split_idx" -le 0 ] && split_idx="$max_w"
                printf "    %s%s%s\n" "$C_MUTED" "${dline:0:$split_idx}" "$C_RESET"
                dline="${dline:$split_idx}"
                dline=$(echo "$dline" | sed -e 's/^[[:space:]]*//')
            done
            printf "    %s%s%s\n" "$C_MUTED" "$dline" "$C_RESET"
        done <<< "$desc_clean"
    fi
    printf "\n"
    format_bash_commands "$commands" "    "
    printf "\n"
}

# Alias for backwards compatibility
print_manual_commands_box() {
    print_manual_commands "$@"
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
                local prev_continuation=0
                while IFS= read -r cline; do
                    [ -z "$cline" ] && continue
                    local line_indent=""
                    if [[ "$cline" =~ ^([[:space:]]+) ]]; then
                        line_indent="${BASH_REMATCH[1]}"
                    fi
                    local eff_indent="        $line_indent"
                    if [ -z "$line_indent" ] && [ "$prev_continuation" -eq 1 ]; then
                        eff_indent="          "
                    fi
                    local trimmed="${cline#"${line_indent}"}"
                    local colored_line
                    colored_line=$(colorize_bash_line "$trimmed")
                    printf "\r${ESC}[2K%s%s\n" "$eff_indent" "$colored_line"
                    rendered=$((rendered + 1))
                    if [[ "$trimmed" =~ (\\|&&|\|\||\|)[[:space:]]*$ ]]; then
                        prev_continuation=1
                    else
                        prev_continuation=0
                    fi
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
            read -rsn2 -t 0.05 rest < "$TTY_INPUT" || true
            if [ -z "$rest" ]; then
                # Touche ESC seule : signal retour (BACK = 255)
                local total_lines=$(( count + 1 ))
                printf "\r${ESC}[%dA${ESC}[J" "$total_lines"
                printf "${ESC}[?25h"
                return 255
            fi
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
            if [ -z "$_r" ]; then
                printf "\r${ESC}[2K"
                return 255
            fi
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
            if [ -z "$_r" ]; then
                printf "\r${ESC}[2K"
                return 255
            fi
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
    if [ "$idx" -eq 255 ]; then
        return 255
    fi
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

format_duration() {
    local s=$1
    if [ "$s" -ge 60 ]; then
        local m=$((s / 60))
        local rem=$((s % 60))
        printf "%dm %02ds" "$m" "$rem"
    else
        printf "%ds" "$s"
    fi
}

detect_error_cause() {
    local log_file="$1"
    [ ! -f "$log_file" ] && return 0
    if grep -iqE "bind: address already in use|port is already allocated" "$log_file" 2>/dev/null; then
        echo "Port réseau en conflit (un service occupe déjà le port demandé)"
    elif grep -iqE "no space left on device" "$log_file" 2>/dev/null; then
        echo "Espace disque insuffisant sur la partition hôte"
    elif grep -iqE "postmaster\.pid.*already exists" "$log_file" 2>/dev/null; then
        echo "Fichier verrou PostgreSQL résiduel détecté dans les données"
    elif grep -iqE "failed to solve.*process.*returned non-zero exit code" "$log_file" 2>/dev/null; then
        echo "Échec d'une étape de compilation ou dépendance dans le conteneur Docker"
    elif grep -iqE "Could not resolve host|Temporary failure in name resolution" "$log_file" 2>/dev/null; then
        echo "Erreur réseau ou échec de résolution DNS"
    elif grep -iqE "Killed|signal: 9" "$log_file" 2>/dev/null; then
        echo "Processus arrêté par l'OOM Killer (mémoire RAM insuffisante)"
    elif grep -iqE "Permission denied \(publickey\)" "$log_file" 2>/dev/null; then
        echo "Authentification SSH refusée : la clé SSH n'est pas reconnue par le serveur"
    elif grep -iqE "Connection refused" "$log_file" 2>/dev/null; then
        echo "Connexion SSH refusée : vérifiez que le serveur écoute bien sur ce port"
    elif grep -iqE "permission denied" "$log_file" 2>/dev/null; then
        echo "Erreur de permissions d'accès au fichier ou au socket"
    fi
}

# Gum-styled dynamic streaming spinner with rolling log viewport
gum_stream_spin() {
    local pid=$1
    local title="$2"
    local log_file="${3:-}"
    local spin_chars=('⠋' '⠙' '⠹' '⠸' '⠼' '⠴' '⠦' '⠧' '⠇' '⠏')
    local spin_len=${#spin_chars[@]}
    local spin_idx=0
    local max_log_lines=3
    local start_time
    start_time=$(date +%s)
    local prev_rendered_lines=0

    if [ -z "$TTY_INPUT" ] || [ ! -t 1 ]; then
        printf "  %s%s...%s\n" "$C_BOLD$C_WHITE" "$title" "$C_RESET"
        local exit_code=0
        wait "$pid" || exit_code=$?
        if [ "$exit_code" -eq 0 ]; then
            printf "  %s✔%s %s%s%s\n" "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$title" "$C_RESET"
        else
            printf "  %s✖%s %s%s%s %s(code %d)%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_WHITE" "$title" "$C_RESET" "$C_DIM" "$exit_code" "$C_RESET"
        fi
        return "$exit_code"
    fi

    local term_cols
    term_cols=$(tput cols 2>/dev/null || echo 80)
    [ -z "$term_cols" ] || [ "$term_cols" -le 0 ] && term_cols=80
    local log_col_max=$(( term_cols - 10 ))
    [ "$log_col_max" -lt 30 ] && log_col_max=30

    printf "${ESC}[?25l"

    while kill -0 "$pid" 2>/dev/null; do
        local now
        now=$(date +%s)
        local elapsed=$(( now - start_time ))
        local duration_str
        duration_str=$(format_duration "$elapsed")

        # 1. Effacer les lignes précédentes du viewport
        if [ "$prev_rendered_lines" -gt 0 ]; then
            printf "\r${ESC}[%dA" "$prev_rendered_lines"
            for ((l=0; l<prev_rendered_lines; l++)); do
                printf "\r${ESC}[2K\n"
            done
            printf "\r${ESC}[%dA" "$prev_rendered_lines"
        fi

        # 2. Afficher la ligne d'en-tête fixe
        local spinner="${spin_chars[spin_idx]}"
        printf "\r${ESC}[2K  %s%s%s %s%s%s %s[%s]%s\n" \
            "$C_PINK" "$spinner" "$C_RESET" \
            "$C_BOLD$C_WHITE" "$title" "$C_RESET" \
            "$C_DIM" "$duration_str" "$C_RESET"

        # 3. Récupérer les dernières lignes de log si disponible
        local lines=()
        if [ -n "$log_file" ] && [ -s "$log_file" ]; then
            while IFS= read -r rline; do
                [ -n "$rline" ] && lines+=("$rline")
            done < <(tail -n "$max_log_lines" "$log_file" 2>/dev/null)
        fi

        # 4. Afficher les lignes de log en continu (borderless, indentées)
        local rendered=0
        for rline in "${lines[@]}"; do
            local clean_line
            clean_line=$(echo "$rline" | tr -d '\r' | cut -c 1-"$log_col_max")
            printf "\r${ESC}[2K      %s%s%s\n" "$C_DIM" "$clean_line" "$C_RESET"
            rendered=$((rendered + 1))
        done

        prev_rendered_lines=$(( rendered + 1 ))
        spin_idx=$(( (spin_idx + 1) % spin_len ))
        sleep 0.08
    done

    local exit_code=0
    wait "$pid" || exit_code=$?
    local end_time
    end_time=$(date +%s)
    local total_duration
    total_duration=$(format_duration "$(( end_time - start_time ))")

    # Nettoyer le viewport dynamique
    if [ "$prev_rendered_lines" -gt 0 ]; then
        printf "\r${ESC}[%dA" "$prev_rendered_lines"
        for ((l=0; l<prev_rendered_lines; l++)); do
            printf "\r${ESC}[2K\n"
        done
        printf "\r${ESC}[%dA" "$prev_rendered_lines"
    fi

    printf "${ESC}[?25h"

    if [ "$exit_code" -eq 0 ]; then
        printf "\r${ESC}[2K  %s✔%s %s%s%s %s(%s)%s\n" \
            "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$title" "$C_RESET" "$C_DIM" "$total_duration" "$C_RESET"
    else
        printf "\r${ESC}[2K  %s✖%s %s%s%s %s(Échec, code %d après %s)%s\n" \
            "$C_PINK" "$C_RESET" "$C_BOLD$C_WHITE" "$title" "$C_RESET" "$C_PINK" "$exit_code" "$total_duration" "$C_RESET"
    fi
    return "$exit_code"
}

# Gestionnaire d'erreur interactif & sans bordure (Borderless TokyoNight)
handle_step_error() {
    local step_title="$1"
    local log_file="$2"
    local work_dir="${3:-$PWD}"

    printf "\n"
    if [ -f "$log_file" ] && [ -s "$log_file" ]; then
        local cause
        cause=$(detect_error_cause "$log_file")
        if [ -n "$cause" ]; then
            printf "  %sℹ%s %sDiagnostic : %s%s%s\n" "$C_GOLD" "$C_RESET" "$C_BOLD$C_WHITE" "$C_GOLD" "$cause" "$C_RESET"
        fi

        printf "  %s•%s %sDernières lignes du journal :%s\n" "$C_PINK" "$C_RESET" "$C_MUTED" "$C_RESET"
        tail -n 12 "$log_file" | while IFS= read -r eline; do
            local clean_eline
            clean_eline=$(echo "$eline" | tr -d '\r' | cut -c 1-85)
            printf "      %s%s%s\n" "$C_DIM" "$clean_eline" "$C_RESET"
        done
        printf "\n"
        printf "  %sℹ%s %sJournal complet enregistré dans : %s%s%s\n\n" "$C_CYAN" "$C_RESET" "$C_MUTED" "$C_BOLD$C_WHITE" "$log_file" "$C_RESET"
    fi

    local actions=(
        "[Rétenter] Relancer immédiatement cette étape"
        "[Shell de secours] Ouvrir un sous-shell de débogage (bash)"
        "[Voir le journal] Parcourir les logs complets (less)"
        "[Ignorer] Poursuivre l'installation malgré l'erreur"
        "[Abandonner] Quitter l'installation"
    )

    while true; do
        gum_choose "Action souhaitée pour résoudre cette erreur" "${actions[@]}"
        local choice=$?

        case "$choice" in
            0) # Rétenter
                return 1 # Code 1 = l'appelant relance son exécution
                ;;
            1) # Shell de secours
                printf "\n  %sℹ Entrée dans le shell de secours dans : %s%s%s\n" "$C_CYAN" "$C_BOLD$C_WHITE" "$work_dir" "$C_RESET"
                printf "  %s  Tapez 'exit' pour revenir au menu d'installation.%s\n\n" "$C_MUTED" "$C_RESET"
                ( cd "$work_dir" && bash < /dev/tty > /dev/tty 2>&1 ) || true
                ;;
            2) # Voir le journal
                if command -v less >/dev/null 2>&1; then
                    less "$log_file" < /dev/tty > /dev/tty || true
                else
                    cat "$log_file"
                fi
                ;;
            3) # Ignorer
                printf "  %s!%s %sÉtape ignorée par l'administrateur.%s\n" "$C_GOLD" "$C_RESET" "$C_MUTED" "$C_RESET"
                return 0 # Code 0 = continuer
                ;;
            4|255|*) # Abandonner
                printf "\n  %s✖%s %sOpération interrompue par l'utilisateur.%s\n\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_WHITE" "$C_RESET"
                exit 1
                ;;
        esac
    done
}

# Gum-styled braille spinner (alias vers gum_stream_spin)
gum_spin() {
    local pid=$1
    local msg="$2"
    local log_file="${3:-}"
    gum_stream_spin "$pid" "$msg" "$log_file"
}

# Direct logged command execution with live streaming and auto-retry error handling
run_logged_step() {
    local title="$1"
    local log_file="$2"
    local work_dir="$3"
    shift 3

    while true; do
        printf "\n  %s⠋%s %s%s...%s\n" "$C_PINK" "$C_RESET" "$C_BOLD$C_WHITE" "$title" "$C_RESET"
        mkdir -p "$(dirname "$log_file")" 2>/dev/null || true
        : > "$log_file"

        local start_time
        start_time=$(date +%s)
        local exit_code=0

        set +e
        (
            cd "$work_dir"
            "$@"
        ) 2>&1 | tee "$log_file"
        exit_code=${PIPESTATUS[0]}
        set -e

        local end_time
        end_time=$(date +%s)
        local duration_str
        duration_str=$(format_duration "$(( end_time - start_time ))")

        if [ "$exit_code" -eq 0 ]; then
            printf "  %s✔%s %s%s%s %s(%s)%s\n" \
                "$C_GREEN" "$C_RESET" "$C_BOLD$C_WHITE" "$title" "$C_RESET" "$C_DIM" "$duration_str" "$C_RESET"
            return 0
        else
            printf "  %s✖%s %s%s%s %s(Échec, code %d après %s)%s\n" \
                "$C_PINK" "$C_RESET" "$C_BOLD$C_WHITE" "$title" "$C_RESET" "$C_PINK" "$exit_code" "$duration_str" "$C_RESET"
            if handle_step_error "$title" "$log_file" "$work_dir"; then
                return 0
            fi
            # Si handle_step_error retourne 1 (Rétenter), la boucle while recommence
        fi
    done
}

# Backwards compatibility aliases
select_menu() { gum_choose "$@"; }
prompt() { gum_input "$1" "$2" "${3:-}"; }
prompt_secret() { gum_password "$1" "$2"; }
spin() { gum_spin "$@"; }
