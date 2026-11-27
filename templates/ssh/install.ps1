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

if ([string]::IsNullOrEmpty($installDir)) {
    if (Test-Path "compose.yaml") {
        $installDir = (Get-Location).Path
    } else {
        $installDir = Join-Path $HOME "gickers.sh"
    }
}

if (-not $nonInteractive) {
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

    Write-Host "`n  $escBold${escCyan}Configuration du déploiement :$escReset"
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

New-Item -ItemType Directory -Force -Path $installDir | Out-Null
Set-Location $installDir

if (-not (Test-Path "compose.yaml")) {
    git clone --depth 1 https://github.com/gickers/gickers.sh.git .
}

New-Item -ItemType Directory -Force -Path ".data", ".data/caddy/sites", "var", "var/cache", "var/log" | Out-Null

$appSecret = [System.Guid]::NewGuid().ToString("N")
if ([string]::IsNullOrEmpty($postgresPassword)) {
    $postgresPassword = [System.Guid]::NewGuid().ToString("N").Substring(0, 24)
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
docker compose --env-file .env.docker run --rm init
docker compose --env-file .env.docker up -d

Write-Host ""
@'
__SUCCESS_CARD__
'@ | Write-Host
Write-Host ""
