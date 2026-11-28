# ==============================================================================
#  gickers.sh — One-Shot Production Installer & Deployment Wizard (Windows PowerShell)
#  Inspired by Charmbracelet Gum (TokyoNight High Contrast Theme)
# ==============================================================================
$ErrorActionPreference = "Stop"

﻿$esc = [char]27
$escCyan = "$esc[1;38;2;125;207;255m"      # #7dcfff
$escPink = "$esc[1;38;2;247;118;142m"      # #f7768e
$escGreen = "$esc[1;38;2;115;218;202m"     # #73daca
$escGold = "$esc[1;38;2;224;175;104m"      # #e0af68
$escWhite = "$esc[1;38;2;192;202;245m"     # #c0caf5
$escMuted = "$esc[38;2;169;177;214m"       # #a9b1d6
$escDim = "$esc[38;2;86;95;137m"           # #565f89
$escBold = "$esc[1m"
$escReset = "$esc[0m"

# TokyoNight Background Styling
$escSelect = "$esc[48;2;41;46;66m"        # #292e42
$escInput = "$esc[48;2;36;40;59m"          # #24283b
$escBadge = "$esc[48;2;59;66;97m"          # #3b4261

function Gum-Choose {
    param(
        [string]$Title,
        [string[]]$Options,
        [int]$DefaultIndex = 0
    )
    $idx = $DefaultIndex
    $count = $Options.Length

    $isInteractive = $true
    try {
        $null = [Console]::KeyAvailable
    } catch {
        $isInteractive = $false
    }

    if (-not $isInteractive) {
        for ($i = 0; $i -lt $count; $i++) {
            Write-Host "  [$($i + 1)] $($Options[$i])"
        }
        $choice = Read-Host "  Votre choix [1]"
        if ($choice -eq "2") { return 1 }
        if ($choice -eq "3") { return 2 }
        if ($choice -eq "4") { return 3 }
        if ($choice -eq "5") { return 4 }
        if ($choice -eq "6") { return 5 }
        return 0
    }

    Write-Host "`n  $escPink?$escReset $escBold$escWhite$Title$escReset"

    function Draw-Items {
        for ($i = 0; $i -lt $count; $i++) {
            if ($i -eq $idx) {
                Write-Host -NoNewline "    $escSelect $escPink❯ $escBold$escCyan$($Options[$i]) $escReset`n"
            } else {
                Write-Host -NoNewline "      $escMuted$($Options[$i])$escReset`n"
            }
        }
    }

    Write-Host -NoNewline "$esc[?25l"
    Draw-Items

    while ($true) {
        $key = [System.Console]::ReadKey($true)
        if ($key.Key -eq [System.ConsoleKey]::UpArrow -or $key.Key -eq [System.ConsoleKey]::K) {
            $idx = ($idx - 1 + $count) % $count
            Write-Host -NoNewline "$esc[$($count)A"
            Draw-Items
        } elseif ($key.Key -eq [System.ConsoleKey]::DownArrow -or $key.Key -eq [System.ConsoleKey]::J) {
            $idx = ($idx + 1) % $count
            Write-Host -NoNewline "$esc[$($count)A"
            Draw-Items
        } elseif ($key.Key -eq [System.ConsoleKey]::Enter) {
            break
        } elseif ($key.KeyChar -eq '1' -and $count -ge 1) {
            $idx = 0; break
        } elseif ($key.KeyChar -eq '2' -and $count -ge 2) {
            $idx = 1; break
        } elseif ($key.KeyChar -eq '3' -and $count -ge 3) {
            $idx = 2; break
        } elseif ($key.KeyChar -eq '4' -and $count -ge 4) {
            $idx = 3; break
        } elseif ($key.KeyChar -eq '5' -and $count -ge 5) {
            $idx = 4; break
        } elseif ($key.KeyChar -eq '6' -and $count -ge 6) {
            $idx = 5; break
        }
    }

    $totalLines = $count + 1
    Write-Host -NoNewline "`r$esc[$($totalLines)A$esc[J"
    Write-Host -NoNewline "$esc[?25h"
    Write-Host "  $escGreen✔$escReset $escBold$escWhite$Title$escReset : $escBadge $escBold$escCyan$($Options[$idx]) $escReset"

    return $idx
}

function Gum-Input {
    param(
        [string]$PromptLabel,
        [string]$DefaultValue = "",
        [string]$Placeholder = ""
    )
    if ([string]::IsNullOrEmpty($Placeholder)) { $Placeholder = $DefaultValue }

    $isInteractive = $true
    try { $null = [Console]::KeyAvailable } catch { $isInteractive = $false }
    if (-not $isInteractive) {
        $in = Read-Host "  $PromptLabel [$DefaultValue]"
        if ([string]::IsNullOrWhiteSpace($in)) { return $DefaultValue }
        return $in
    }

    $inputStr = ""
    function Redraw-Input {
        Write-Host -NoNewline "`r$esc[2K  $escPink❯$escReset $escBold$escWhite$PromptLabel$escReset : "
        if ([string]::IsNullOrEmpty($inputStr)) {
            Write-Host -NoNewline "$escInput $escDim$Placeholder $escReset$esc[$($Placeholder.Length + 2)D"
        } else {
            Write-Host -NoNewline "$escInput $escGold$inputStr $escReset"
        }
    }

    Redraw-Input
    while ($true) {
        $k = [System.Console]::ReadKey($true)
        if ($k.Key -eq [System.ConsoleKey]::Enter) {
            break
        } elseif ($k.Key -eq [System.ConsoleKey]::Backspace) {
            if ($inputStr.Length -gt 0) {
                $inputStr = $inputStr.Substring(0, $inputStr.Length - 1)
            }
            Redraw-Input
        } elseif ($k.KeyChar -ge 32) {
            $inputStr += $k.KeyChar
            Redraw-Input
        }
    }

    $final = if ([string]::IsNullOrWhiteSpace($inputStr)) { $DefaultValue } else { $inputStr }
    Write-Host "`r$esc[2K  $escGreen✔$escReset $escBold$escWhite$PromptLabel$escReset : $escBadge $escGold$final $escReset"
    return $final
}

function Gum-Password {
    param(
        [string]$PromptLabel,
        [string]$Placeholder = "(optionnelle, laisser vide)"
    )

    $isInteractive = $true
    try { $null = [Console]::KeyAvailable } catch { $isInteractive = $false }
    if (-not $isInteractive) {
        $sec = Read-Host -AsSecureString "  $PromptLabel"
        $b = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)
        return [System.Runtime.InteropServices.Marshal]::PtrToStringAuto($b)
    }

    $inputStr = ""
    function Redraw-Pw {
        Write-Host -NoNewline "`r$esc[2K  $escPink❯$escReset $escBold$escWhite$PromptLabel$escReset : "
        if ([string]::IsNullOrEmpty($inputStr)) {
            Write-Host -NoNewline "$escInput $escDim$Placeholder $escReset$esc[$($Placeholder.Length + 2)D"
        } else {
            $bullets = "•" * $inputStr.Length
            Write-Host -NoNewline "$escInput $escPink$bullets $escReset"
        }
    }

    Redraw-Pw
    while ($true) {
        $k = [System.Console]::ReadKey($true)
        if ($k.Key -eq [System.ConsoleKey]::Enter) {
            break
        } elseif ($k.Key -eq [System.ConsoleKey]::Backspace) {
            if ($inputStr.Length -gt 0) {
                $inputStr = $inputStr.Substring(0, $inputStr.Length - 1)
            }
            Redraw-Pw
        } elseif ($k.KeyChar -ge 32) {
            $inputStr += $k.KeyChar
            Redraw-Pw
        }
    }

    $disp = if ([string]::IsNullOrEmpty($inputStr)) { "$escDim(aucune)" } else { "$escPink" + ("•" * $inputStr.Length) }
    Write-Host "`r$esc[2K  $escGreen✔$escReset $escBold$escWhite$PromptLabel$escReset : $escBadge $disp $escReset"
    return $inputStr
}

function Gum-Confirm {
    param(
        [string]$PromptLabel,
        [bool]$DefaultYes = $true
    )
    $opts = @("Oui, continuer", "Non, annuler")
    $defIdx = 0
    if (-not $DefaultYes) {
        $opts = @("Non, annuler", "Oui, continuer")
        $defIdx = 0
    }
    $chosen = Gum-Choose -Title $PromptLabel -Options $opts -DefaultIndex $defIdx
    if ($DefaultYes) {
        return ($chosen -eq 0)
    } else {
        return ($chosen -eq 1)
    }
}

function Gum-Spin {
    param(
        [System.Diagnostics.Process]$Proc,
        [string]$Message
    )
    $chars = @('⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏')
    $i = 0
    Write-Host -NoNewline "$esc[?25l"
    while (-not $Proc.HasExited) {
        Write-Host -NoNewline "`r  $escPink$($chars[$i])$escReset $escBold$escWhite$Message$escReset"
        $i = ($i + 1) % 10
        Start-Sleep -Milliseconds 70
    }
    Write-Host -NoNewline "$esc[?25h"
    Write-Host "`r$esc[2K  $escGreen✔$escReset $escBold$escWhite$Message$escReset"
}

function Prompt-Password-With-Confirm {
    param(
        [string]$Label
    )
    while ($true) {
        $p1 = Gum-Password -PromptLabel $Label -Placeholder "(optionnelle, laisser vide)"
        if ([string]::IsNullOrEmpty($p1)) {
            return ""
        }
        $p2 = Gum-Password -PromptLabel "Confirmation du mot de passe" -Placeholder "(retapez votre mot de passe)"
        if ($p1 -eq $p2) {
            return $p1
        }
        Write-Host "  $escPink✖$escReset $escBold${escPink}Les mots de passe ne correspondent pas. Veuillez réessayer.$escReset"
    }
}

function Gum-PasswordWithConfirm {
    param([string]$PromptLabel)
    while ($true) {
        $p1 = Gum-Password -PromptLabel $PromptLabel -Placeholder "(optionnelle, laisser vide)"
        if ([string]::IsNullOrEmpty($p1)) {
            return ""
        }
        $p2 = Gum-Password -PromptLabel "Confirmation de la passphrase" -Placeholder "(retapez votre passphrase)"
        if ($p1 -eq $p2) {
            return $p1
        }
        Write-Host "  $escPink✖$escReset $escBold$escPink Les passphrases ne correspondent pas. Veuillez réessayer.$escReset"
    }
}


function Gum-PastePubkey {
    while ($true) {
        Write-Host -NoNewline "  $escPink❯$escReset $escBold$escWhite Collez votre clé publique SSH (.pub)$escReset : "
        $pasted = (Read-Host).Trim()

        if ([string]::IsNullOrWhiteSpace($pasted)) {
            Write-Host "  $escPink✖$escReset $escBold$escPink Aucune clé collée. Veuillez réessayer.$escReset"
            continue
        }
        if ($pasted -notmatch '^(ssh-ed25519|ssh-rsa|ecdsa-sha2-|sk-ssh-ed25519|sk-ecdsa-)') {
            Write-Host "  $escPink✖$escReset $escBold$escPink Format invalide (doit commencer par ssh-ed25519, ssh-rsa, etc.). Réessayez :$escReset"
            continue
        }
        $parts = $pasted -split '\s+'
        $summary = $parts[0]
        if ($parts.Length -ge 3) { $summary += " ($($parts[2]))" }
        Write-Host "  $escGreen✔$escReset $escBold$escWhite Clé publique SSH reçue$escReset : $escBadge $escGold$summary $escReset"
        return $pasted
    }
}

function Select-Menu { Gum-Choose @args }



# Display Termstrap Header
@'
[38;2;65;72;104m[48;2;31;35;53m╭────────────────────────────────────────────────────────────────────────╮[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m🚀 Gickers.sh — Installation & Déploiement One-Shot [Production VPS &[m[48;2;31;35;53m [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53mLocal][m[48;2;31;35;53m                                                                [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;86;95;137m[38;2;86;95;137mCible : codes.sh • Git Forge & Containers Isolés • Caddy SSL Auto[m[48;2;31;35;53m[m[48;2;31;35;53m[m[48;2;31;35;53m     [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m╰────────────────────────────────────────────────────────────────────────╯[m
                                                                          
'@ | Write-Host

$domain = "codes.sh"
$appName = "Gickers.sh"
$mode = "docker"
$sshPort = "22"
$httpPort = "80"
$httpsPort = "443"
$installDir = ""
$systemUser = ""
$adminSshKey = ""
$adminUser = "admin"
$postgresUser = "gickers"
$postgresDb = "gickers"
$postgresPassword = ""
$anonAccess = "read-only"
$allowSignup = "true"
$allowKeyless = "true"
$lfsEnabled = "true"
$nonInteractive = ("0" -eq "1")
$dryRun = ("0" -eq "1")
$stepDocker = "auto"
$stepFirewall = "auto"
$hardReset = ("0" -eq "1")

# Default system user
if ([string]::IsNullOrEmpty($systemUser)) {
    $systemUser = $env:USERNAME
}

if ([string]::IsNullOrEmpty($installDir)) {
    if (Test-Path "compose.yaml") {
        $installDir = (Get-Location).Path
    } else {
        $installDir = Join-Path $HOME "gickers.sh"
    }
}

# Auto-detect existing Docker
$dockerInstalled = 0
try {
    $null = docker --version
    $dockerInstalled = 1
} catch {
    $dockerInstalled = 0
}

# Auto-detect existing Admin SSH key
if ([string]::IsNullOrEmpty($adminSshKey)) {
    $edKey = Join-Path $HOME ".ssh\id_ed25519.pub"
    $rsaKey = Join-Path $HOME ".ssh\id_rsa.pub"
    if (Test-Path $edKey) {
        $adminSshKey = (Get-Content $edKey -Raw).Trim()
    } elseif (Test-Path $rsaKey) {
        $adminSshKey = (Get-Content $rsaKey -Raw).Trim()
    }
}

if (-not $nonInteractive) {
    if ((Test-Path "compose.yaml") -or (Test-Path ".env.docker") -or (Test-Path (Join-Path $installDir "compose.yaml"))) {
        $existOpts = @(
            "Conserver les données et mettre à jour l'application (Idempotent - Recommandé)",
            "Réinitialisation complète / Hard Reset (⚠️  Supprime base de données, volumes et dépôts)"
        )
        $existChoice = Gum-Choose -Title "Une installation existante a été détectée dans $installDir" -Options $existOpts
        if ($existChoice -eq 1) {
            $hardReset = $true
        } else {
            $hardReset = $false
            Write-Host "  $escGreen✔$escReset $escBold${escWhite}Mode idempotent : vos données et configurations existantes seront conservées.$escReset`n"
        }
    }

    if ($hardReset) {
        Write-Host "`n  $escPink⚠️  ATTENTION : DEMANDE DE RÉINITIALISATION COMPLÈTE (HARD RESET)$escReset"
        Write-Host "  $escPink Cette action est IRRÉVERSIBLE et va supprimer définitivement :$escReset"
        Write-Host "    • Tous les dépôts Git et volumes Docker (gickers-data, gickers-db)"
        Write-Host "    • La base de données PostgreSQL (comptes, clés SSH, tickets)`n"

        $confirmOpts = @(
            "Non, annuler et conserver mes données intactes (Recommandé)",
            "Oui, je confirme vouloir supprimer TOUTES les données"
        )
        $confirm1 = Gum-Choose -Title "Confirmation 1/2 : Souhaitez-vous continuer la réinitialisation ?" -Options $confirmOpts
        if ($confirm1 -ne 1) {
            Write-Host "  $escGreen✔$escReset $escBold${escWhite}Réinitialisation annulée. Vos données sont préservées.$escReset`n"
            $hardReset = $false
        } else {
            $resetConfirm = Gum-Input -PromptLabel "Confirmation 2/2 : Tapez 'RESET' en majuscules pour confirmer" -DefaultValue "" -Placeholder "RESET"
            if ($resetConfirm -eq "RESET") {
                $hardReset = $true
                Write-Host "  $escPink✔$escReset $escBold${escWhite}Double confirmation validée. Hard reset enclenché.$escReset`n"
            } else {
                Write-Host "  $escGreen✔$escReset $escBold${escWhite}Saisie non conforme. Réinitialisation annulée, données préservées.$escReset`n"
                $hardReset = $false
            }
        }
    }

    # 0. User & Isolation choice
    $userOpts = @(
        "Utiliser l'utilisateur Windows actuel ($env:USERNAME) [Recommandé]",
        "Spécifier un compte ou utilisateur de service dédié..."
    )
    $userChoice = Gum-Choose -Title "Compte d'exécution pour gickers.sh" -Options $userOpts
    if ($userChoice -eq 1) {
        $systemUser = Gum-Input -PromptLabel "Nom du compte utilisateur" -DefaultValue "gickers"
    }

    # 1. Étape Docker
    if ($dockerInstalled -eq 1) {
        Write-Host "  $escGreen✔$escReset $escBold${escWhite}Docker Desktop / Engine déjà installé et actif.$escReset"
    } else {
        $dockerOpts = @(
            "[Afficher les commandes] Télécharger et installer Docker Desktop pour Windows",
            "[Ignorer cette étape] J'installerai Docker plus tard manuellement"
        )
        $dockerChoice = Gum-Choose -Title "Docker non détecté sur cette machine" -Options $dockerOpts
        if ($dockerChoice -eq 0) {
            Write-Host "`n  $escBold${escCyan}Installation manuelle recommandée de Docker Desktop :$escReset"
            Write-Host "    $escGold winget install -e --id Docker.DockerDesktop $escReset"
            Write-Host "    ou téléchargez l'installateur depuis : https://www.docker.com/products/docker-desktop/`n"
        }
    }

    # 2. Pare-feu Windows
    $fwOpts = @(
        "[Exécution automatique] Ouvrir les ports Web ($httpPort, $httpsPort) et Git SSH ($sshPort) dans le pare-feu Windows",
        "[Ignorer cette étape] Je gère mes règles de pare-feu moi-même",
        "[Afficher les commandes] Voir les commandes PowerShell de configuration du pare-feu"
    )
    $fwChoice = Gum-Choose -Title "Pare-feu Windows Defender" -Options $fwOpts
    if ($fwChoice -eq 2) {
        Write-Host "`n  $escBold${escCyan}Commandes PowerShell du pare-feu Windows :$escReset"
        Write-Host "    New-NetFirewallRule -DisplayName 'Gickers HTTP' -Direction Inbound -LocalPort $httpPort -Protocol TCP -Action Allow"
        Write-Host "    New-NetFirewallRule -DisplayName 'Gickers HTTPS' -Direction Inbound -LocalPort $httpsPort -Protocol TCP -Action Allow"
        Write-Host "    New-NetFirewallRule -DisplayName 'Gickers Git SSH' -Direction Inbound -LocalPort $sshPort -Protocol TCP -Action Allow`n"
        $stepFirewall = "show"
    } elseif ($fwChoice -eq 1) {
        $stepFirewall = "skip"
    } else {
        $stepFirewall = "auto"
    }

    # 3. Paramètres applicatifs
    $domain = Gum-Input -PromptLabel "Domaine public (FQDN)" -DefaultValue $domain -Placeholder "ex: gits.run ou code.mon-domaine.com"
    $domain = $domain.Trim()

    $appName = Gum-Input -PromptLabel "Nom d'usage de la forge" -DefaultValue $appName -Placeholder "ex: Gickers.sh"

    $installDir = Gum-Input -PromptLabel "Répertoire d'installation" -DefaultValue $installDir

    $sshOpts = @(
        "Port 22 (Standard Git)",
        "Port 2223 (Port alternatif)",
        "Port personnalisé..."
    )
    $sshChoice = Gum-Choose -Title "Port SSH public pour Git" -Options $sshOpts
    if ($sshChoice -eq 0) { $sshPort = "22" }
    elseif ($sshChoice -eq 1) { $sshPort = "2223" }
    else { $sshPort = Gum-Input -PromptLabel "Numéro de port SSH" -DefaultValue "2222" }

    $adminUser = Gum-Input -PromptLabel "Nom d'utilisateur administrateur" -DefaultValue $adminUser

    # 4. Variables d'environnement & Secrets (Valeurs Intelligentes)
    if (Test-Path ".env.docker") {
        $prevSecret = (Get-Content ".env.docker" | Where-Object { $_ -match '^APP_SECRET=(.+)' } | ForEach-Object { $matches[1] })
        $prevPass = (Get-Content ".env.docker" | Where-Object { $_ -match '^POSTGRES_PASSWORD=(.+)' } | ForEach-Object { $matches[1] })
        if ($prevSecret) { $appSecret = $prevSecret }
        if ($prevPass) { $postgresPassword = $prevPass }
    }
    if ([string]::IsNullOrEmpty($appSecret)) {
        $appSecret = [System.Guid]::NewGuid().ToString("N")
    }
    if ([string]::IsNullOrEmpty($postgresPassword)) {
        $postgresPassword = [System.Guid]::NewGuid().ToString("N").Substring(0, 24)
    }

    $envOpts = @(
        "Conserver ces valeurs recommandées (Automatique & Sécurisé - Entrée rapide)",
        "Personnaliser les variables d'environnement (Secret, Base de données)..."
    )
    $envChoice = Gum-Choose -Title "Variables d'environnement & Secrets" -Options $envOpts
    if ($envChoice -eq 1) {
        $appSecret = Gum-Input -PromptLabel "Clé APP_SECRET (Symfony)" -DefaultValue $appSecret
        $postgresUser = Gum-Input -PromptLabel "Utilisateur PostgreSQL" -DefaultValue $postgresUser
        $postgresPassword = Gum-Input -PromptLabel "Mot de passe PostgreSQL" -DefaultValue $postgresPassword
        $postgresDb = Gum-Input -PromptLabel "Nom de la base PostgreSQL" -DefaultValue $postgresDb
    }

    # 5. Summary & Confirmation
    Write-Host "`n  $escBold${escCyan}Configuration finale du déploiement :$escReset"
    Write-Host "    • Utilisateur :   $escBold$escGreen$systemUser$escReset"
    Write-Host "    • Domaine :       $escBold$escWhite$domain$escReset"
    Write-Host "    • Application :   $escBold$escWhite$appName$escReset"
    Write-Host "    • Répertoire :    $escGold$installDir$escReset"
    Write-Host "    • Port SSH :      $escCyan$sshPort$escReset"
    Write-Host "    • Administrateur: $escGreen@$adminUser$escReset`n"

    $confirmed = Gum-Confirm -PromptLabel "Lancer l'installation et le déploiement sur cette machine ?" -DefaultYes $true
    if (-not $confirmed) {
        Write-Host "`n  $escCyanℹ$escReset $escMuted Déploiement annulé.$escReset`n"
        exit 0
    }
}

if ($dryRun) {
    Write-Host "`n  $escGreen✔$escReset $escBold${escGreen}[DRY-RUN] Validation réussie. Aucune modification.$escReset`n"
    exit 0
}

# Règles pare-feu si sélectionné en mode auto
if ($stepFirewall -eq "auto") {
    try {
        New-NetFirewallRule -DisplayName "Gickers HTTP" -Direction Inbound -LocalPort [int]$httpPort -Protocol TCP -Action Allow -ErrorAction SilentlyContinue | Out-Null
        New-NetFirewallRule -DisplayName "Gickers HTTPS" -Direction Inbound -LocalPort [int]$httpsPort -Protocol TCP -Action Allow -ErrorAction SilentlyContinue | Out-Null
        New-NetFirewallRule -DisplayName "Gickers Git SSH" -Direction Inbound -LocalPort [int]$sshPort -Protocol TCP -Action Allow -ErrorAction SilentlyContinue | Out-Null
        Write-Host "  $escGreen✔$escReset $escBold$escWhite Règles du pare-feu Windows appliquées.$escReset"
    } catch {
        Write-Host "  $escGold!$escReset $escMuted Impossible d'ajouter automatiquement les règles de pare-feu (droits administrateur requis).$escReset"
    }
}

New-Item -ItemType Directory -Force -Path $installDir | Out-Null
Set-Location $installDir

if ($hardReset) {
    Write-Host "  $escPink⚙$escReset $escBold${escWhite}Exécution du Hard Reset (suppression des conteneurs, volumes et bases de données)...$escReset"
    docker compose --env-file .env.docker down -v --remove-orphans 2>$null
    docker compose down -v --remove-orphans 2>$null
    docker volume rm -f gickers-sh_gickers-data gickers-sh_gickers-db gickers-sh_gickers-var gickers-sh_gickers-deploy-engine 2>$null
    if (Test-Path ".env.docker") {
        Move-Item ".env.docker" ".env.docker.reset.bak" -Force 2>$null
    }
    Remove-Item -Recurse -Force ".data", "var/cache", "var/log" 2>$null
    Write-Host "  $escGreen✔$escReset $escBold${escWhite}Hard reset terminé. L'environnement est maintenant vierge.$escReset`n"
}

if (Test-Path ".git") {
    git fetch --depth 1 origin main 2>$null
    git reset --hard origin/main 2>$null
} elseif (-not (Test-Path "compose.yaml")) {
    git clone --depth 1 https://github.com/gickerssh/gickers.sh.git .
}

New-Item -ItemType Directory -Force -Path ".data", ".data/caddy/sites", "var", "var/cache", "var/log" | Out-Null

$appSecret = [System.Guid]::NewGuid().ToString("N")
if ([string]::IsNullOrEmpty($postgresPassword)) {
    $postgresPassword = [System.Guid]::NewGuid().ToString("N").Substring(0, 24)
}

$adminClaimToken = ""
if ([string]::IsNullOrEmpty($adminSshKey)) {
    $adminClaimToken = [System.Guid]::NewGuid().ToString("N")
}

if ((-not $hardReset) -and (Test-Path ".env.docker")) {
    $existingSecret = (Get-Content ".env.docker" | Where-Object { $_ -match '^APP_SECRET=(.+)' } | ForEach-Object { $matches[1] })
    $existingPass = (Get-Content ".env.docker" | Where-Object { $_ -match '^POSTGRES_PASSWORD=(.+)' } | ForEach-Object { $matches[1] })
    $existingToken = (Get-Content ".env.docker" | Where-Object { $_ -match '^SOFT_SERVE_INITIAL_ADMIN_TOKEN=(.+)' } | ForEach-Object { $matches[1].Trim('"') })
    $existingKeys = (Get-Content ".env.docker" | Where-Object { $_ -match '^SOFT_SERVE_INITIAL_ADMIN_KEYS=(.+)' } | ForEach-Object { $matches[1].Trim('"') })
    if ($existingSecret) { $appSecret = $existingSecret }
    if ($existingPass) { $postgresPassword = $existingPass }
    if ($existingToken -and [string]::IsNullOrEmpty($adminSshKey)) { $adminClaimToken = $existingToken }
    if ($existingKeys -and [string]::IsNullOrEmpty($adminSshKey)) { $adminSshKey = $existingKeys }
}

$sshUrl = if ($sshPort -eq "22") { "ssh://$domain" } else { "ssh://${domain}:${sshPort}" }

$envContent = @"
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$appSecret
POSTGRES_USER=$postgresUser
POSTGRES_PASSWORD=$postgresPassword
POSTGRES_DB=$postgresDb
HTTP_PORT=$httpPort
HTTPS_PORT=$httpsPort
SSH_PORT=$sshPort
APP_DOMAIN=$domain
SOFT_SERVE_HTTP_PUBLIC_URL=https://$domain
SOFT_SERVE_SSH_PUBLIC_URL=$sshUrl
CADDY_TLS=""
SOFT_SERVE_NAME=$appName
SOFT_SERVE_LFS_ENABLED=$lfsEnabled
SOFT_SERVE_ANON_ACCESS=$anonAccess
SOFT_SERVE_ALLOW_KEYLESS=$allowKeyless
SOFT_SERVE_INITIAL_ADMIN_KEYS="$adminSshKey"
SOFT_SERVE_INITIAL_ADMIN_TOKEN="$adminClaimToken"
SOFT_SERVE_ALLOW_SIGNUP=$allowSignup
DOCKER_HOST=tcp://deploy-dockerd:2375
DEPLOY_GATEWAY_HOST=deploy-dockerd
DEPLOY_DOCKER_RUNTIME=runsc
DEPLOY_GVISOR_PLATFORM=systrap
"@

Set-Content -Path ".env.docker" -Value $envContent -Encoding UTF8

Write-Host "  $escGreen✔$escReset $escBold$escWhite Configuration .env.docker initialisée.$escReset"

docker compose --env-file .env.docker build
docker compose --env-file .env.docker up -d postgres

Start-Sleep -Seconds 3
docker compose --env-file .env.docker run -T --rm init
docker compose --env-file .env.docker up -d

Write-Host ""
@'
[38;2;65;72;104m[48;2;31;35;53m╭────────────────────────────────────────────────────────────────────────╮[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;158;206;106m[38;2;158;206;106m✓ Installation et déploiement de Gickers.sh terminés avec succès ![m[48;2;31;35;53m[m[48;2;31;35;53m[m[48;2;31;35;53m    [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;86;95;137m[38;2;86;95;137mVotre forge Git et plateforme de conteneurs est opérationnelle :[m[48;2;31;35;53m[m[48;2;31;35;53m[m[48;2;31;35;53m      [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m    • Interface Web : https://codes.sh[m[48;2;31;35;53m                                [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m    • Clone SSH : git clone ssh://codes.sh/<user>/<repo>.git[m[48;2;31;35;53m          [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m    • Clone HTTP : https://codes.sh/<user>/<repo>.git[m[48;2;31;35;53m                 [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m    • Accès CLI / TUI : ssh codes.sh[m[48;2;31;35;53m                                  [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m│[m[48;2;31;35;53m [m[48;2;31;35;53m    • Info serveur : ssh codes.sh info[m[48;2;31;35;53m                                [m[48;2;31;35;53m [m[48;2;31;35;53m[38;2;65;72;104m[48;2;31;35;53m│[m
[38;2;65;72;104m[48;2;31;35;53m╰────────────────────────────────────────────────────────────────────────╯[m
'@ | Write-Host
Write-Host ""

if (-not [string]::IsNullOrEmpty($adminClaimToken)) {
    $sshPortOpt = if ($sshPort -ne "22") { "-p $sshPort " } else { "" }
    Write-Host "  $escBold${escGreen}🔑 Initialisation Administrateur (Token sécurisé à usage unique)$escReset"
    Write-Host "  ${escMuted}Initialisez votre compte administrateur depuis votre poste client :$escReset`n"
    Write-Host "    $escCyan${escBold}ssh $sshPortOpt$domain signup --token $adminClaimToken | iex$escReset`n"
    Write-Host "  ${escMuted}Sur Linux / macOS (Bash) :$escReset"
    Write-Host "    $escCyan${escBold}ssh $sshPortOpt$domain signup --token $adminClaimToken | bash$escReset`n"
}
