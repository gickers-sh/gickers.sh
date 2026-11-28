# ==============================================================================
#  gickers.sh — One-Shot Production Installer & Deployment Wizard (Windows PowerShell)
#  Inspired by Charmbracelet Gum (TokyoNight High Contrast Theme)
# ==============================================================================
$ErrorActionPreference = "Stop"

__GUM_LIB__

# Display Termstrap Header
@'
__HEADER_CARD__
'@ | Write-Host

$domain = "__DOMAIN__"
$appName = "__APP_NAME__"
$sshPort = "__SSH_PORT__"
$httpPort = "__HTTP_PORT__"
$httpsPort = "__HTTPS_PORT__"
$installDir = "__INSTALL_DIR__"
$systemUser = "__SYSTEM_USER__"
$adminSshKey = "__ADMIN_KEY__"
$adminUser = "__ADMIN_USER__"
$postgresUser = "__POSTGRES_USER__"
$postgresDb = "__POSTGRES_DB__"
$postgresPassword = "__POSTGRES_PASSWORD__"
$anonAccess = "__ANON_ACCESS__"
$allowSignup = "__ALLOW_SIGNUP__"
$allowKeyless = "__ALLOW_KEYLESS__"
$lfsEnabled = "__LFS_ENABLED__"
$nonInteractive = ("__NON_INTERACTIVE__" -eq "1")
$dryRun = ("__DRY_RUN__" -eq "1")
$stepDocker = "__STEP_DOCKER__"
$stepFirewall = "__STEP_FIREWALL__"

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

    # 4. Summary & Confirmation
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
__SUCCESS_CARD__
'@ | Write-Host
Write-Host ""

if (-not [string]::IsNullOrEmpty($adminClaimToken)) {
    $sshPortOpt = if ($sshPort -ne "22") { "-p $sshPort " } else { "" }
    Write-Host "  $escGreen╭────────────────────────────────────────────────────────────────────────╮$escReset"
    Write-Host "  $escGreen│$escReset $escBold$escWhite🔑 Initialisation Administrateur (Token sécurisé à usage unique)       $escGreen│$escReset"
    Write-Host "  $escGreen├────────────────────────────────────────────────────────────────────────┤$escReset"
    Write-Host "  $escGreen│$escReset Initialisez votre compte administrateur depuis votre poste client :     $escGreen│$escReset"
    Write-Host "  $escGreen│$escReset                                                                        $escGreen│$escReset"
    Write-Host "  $escGreen│$escReset   $escCyan${escBold}ssh $sshPortOpt$domain signup --token $adminClaimToken | iex$escReset"
    Write-Host "  $escGreen│$escReset                                                                        $escGreen│$escReset"
    Write-Host "  $escGreen│$escReset Sur Linux / macOS (Bash) :                                              $escGreen│$escReset"
    Write-Host "  $escGreen│$escReset   $escCyan${escBold}ssh $sshPortOpt$domain signup --token $adminClaimToken | bash$escReset"
    Write-Host "  $escGreen╰────────────────────────────────────────────────────────────────────────╯$escReset`n"
}
