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
$mode = "__MODE__"
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
$hardReset = ("__HARD_RESET__" -eq "1")

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
$dockerVerOut = Invoke-QuietNative { docker --version }
if ($LASTEXITCODE -eq 0 -and -not [string]::IsNullOrWhiteSpace(($dockerVerOut | Out-String))) {
    $dockerInstalled = 1
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

# ==============================================================================
# INVENTAIRE EN AMONT DES INSTANCES GICKERS.SH & CHOIX D'ACTION
# ==============================================================================
$existingInstall = $false
$existingDomain = ""
$createNewInstance = $false

$detectedDirs = [System.Collections.Generic.List[string]]::new()
$candidatePaths = [System.Collections.Generic.List[string]]::new()

if (-not [string]::IsNullOrEmpty($installDir)) { $candidatePaths.Add($installDir) }
$candidatePaths.Add((Get-Location).Path)
$candidatePaths.Add((Join-Path $HOME "gickers.sh"))
$candidatePaths.Add("C:\gickers")
$candidatePaths.Add("C:\codes.sh")

# Détection Docker Compose via labels de conteneurs et images officielles
if ($dockerInstalled -eq 1) {
    $dockerDirs = Invoke-QuietNative { docker ps -a --filter "ancestor=gickers-sh:latest" --format '{{ index .Labels "com.docker.compose.project.working_dir" }}' }
    if ($dockerDirs) {
        foreach ($d in ($dockerDirs -split "`r?`n")) {
            if (-not [string]::IsNullOrWhiteSpace($d) -and (Test-Path $d)) {
                $candidatePaths.Add($d.Trim())
            }
        }
    }
    $dockerDirs2 = Invoke-QuietNative { docker ps -a --filter "label=com.docker.compose.project=gickers-sh" --format '{{ index .Labels "com.docker.compose.project.working_dir" }}' }
    if ($dockerDirs2) {
        foreach ($d in ($dockerDirs2 -split "`r?`n")) {
            if (-not [string]::IsNullOrWhiteSpace($d) -and (Test-Path $d)) {
                $candidatePaths.Add($d.Trim())
            }
        }
    }
}

# Filtrage et déduplication
foreach ($cDir in $candidatePaths) {
    if ([string]::IsNullOrWhiteSpace($cDir) -or -not (Test-Path $cDir)) { continue }
    $hasCompose = (Test-Path (Join-Path $cDir "compose.yaml")) -or (Test-Path (Join-Path $cDir "docker-compose.yml"))
    $hasEnv = (Test-Path (Join-Path $cDir ".env.docker")) -or (Test-Path (Join-Path $cDir ".env.local"))
    if ($hasCompose -or $hasEnv) {
        $fullPath = (Get-Item $cDir).FullName
        if (-not $detectedDirs.Contains($fullPath)) {
            $detectedDirs.Add($fullPath)
        }
    }
}

# Collecte des métadonnées et runtime state pour chaque instance
$detectedDomains = [System.Collections.Generic.List[string]]::new()
$detectedLabels = [System.Collections.Generic.List[string]]::new()

foreach ($dPath in $detectedDirs) {
    $instDom = ""
    $envDocker = Join-Path $dPath ".env.docker"
    $envLocal = Join-Path $dPath ".env.local"
    if (Test-Path $envDocker) {
        $m = (Get-Content $envDocker | Where-Object { $_ -match '^APP_DOMAIN=(.+)' } | ForEach-Object { $matches[1].Trim(' "') } | Select-Object -First 1)
        if ($m) { $instDom = $m }
    } elseif (Test-Path $envLocal) {
        $m = (Get-Content $envLocal | Where-Object { $_ -match '^APP_DOMAIN=(.+)' } | ForEach-Object { $matches[1].Trim(' "') } | Select-Object -First 1)
        if ($m) { $instDom = $m }
    }
    if ([string]::IsNullOrEmpty($instDom)) { $instDom = "domaine non spécifié" }
    $detectedDomains.Add($instDom)

    $instState = "Arrêté"
    if ($dockerInstalled -eq 1) {
        $cList = Invoke-QuietNative { docker ps -a --filter "label=com.docker.compose.project.working_dir=$dPath" -q }
        if ($cList) {
            $totalCnt = ($cList -split "`r?`n" | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }).Count
            $rList = Invoke-QuietNative { docker ps --filter "label=com.docker.compose.project.working_dir=$dPath" -q }
            $runningCnt = if ($rList) { ($rList -split "`r?`n" | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }).Count } else { 0 }
            if ($totalCnt -gt 0) {
                if ($runningCnt -gt 0) {
                    $instState = "Docker actif (${runningCnt}/${totalCnt} UP)"
                } else {
                    $instState = "Docker arrêté (0/${totalCnt})"
                }
            }
        }
    }

    $instSsh = "22"
    if (Test-Path $envDocker) {
        $s = (Get-Content $envDocker | Where-Object { $_ -match '^SSH_PORT=(.+)' } | ForEach-Object { $matches[1].Trim(' "') } | Select-Object -First 1)
        if ($s) { $instSsh = $s }
    }

    $detectedLabels.Add("$dPath • $instDom [$instState] (SSH: $instSsh)")
}

if ($detectedDirs.Count -gt 0 -and -not $nonInteractive) {
    $targetIndex = 0
    if ($detectedDirs.Count -gt 1) {
        $instPickerOpts = @()
        for ($i = 0; $i -lt $detectedDirs.Count; $i++) {
            if ($i -eq 0) {
                $instPickerOpts += "Gérer l'instance : $($detectedLabels[$i]) [Recommandé]"
            } else {
                $instPickerOpts += "Gérer l'instance : $($detectedLabels[$i])"
            }
        }
        $instPickerOpts += "Créer et déployer une NOUVELLE instance gickers.sh indépendante"

        $targetIndex = Gum-Choose -Title "Instances gickers.sh détectées sur cette machine" -Options $instPickerOpts
        if ($targetIndex -ge $detectedDirs.Count) {
            $existingInstall = $false
            $createNewInstance = $true
            $installDir = ""
        } else {
            $existingInstall = $true
            $installDir = $detectedDirs[$targetIndex]
            $existingDomain = $detectedDomains[$targetIndex]
            $domain = $existingDomain
        }
    } else {
        $existingInstall = $true
        $installDir = $detectedDirs[0]
        $existingDomain = $detectedDomains[0]
        $domain = $existingDomain
    }

    if ($existingInstall) {
        $actionOpts = @(
            "Mettre à jour l'application (Update) [Recommandé]",
            "Redémarrer les services (Restart)",
            "Reconstruire les conteneurs (Rebuild)",
            "Redéployer / Réappliquer migrations & cache (Redeploy)",
            "Éditer la configuration (.env.docker / domaine / ports)",
            "Réinitialisation complète / Hard Reset (⚠️  Supprime base de données et dépôts)",
            "Supprimer définitivement cette instance / Delete (⚠️  Détruit conteneurs, volumes et dossier)",
            "Créer et déployer une NOUVELLE instance gickers.sh indépendante"
        )
        $actionTitle = "Gestion de l'instance : $existingDomain ($installDir)"
        $actionSel = Gum-Choose -Title $actionTitle -Options $actionOpts
        $selectedAction = $actionOpts[$actionSel]

        if ($selectedAction -match "Update") {
            Manage-InstanceUpdate -Dir $installDir
        } elseif ($selectedAction -match "Restart") {
            Manage-InstanceRestart -Dir $installDir
        } elseif ($selectedAction -match "Rebuild") {
            Manage-InstanceRebuild -Dir $installDir
        } elseif ($selectedAction -match "Redeploy") {
            Manage-InstanceRedeploy -Dir $installDir
        } elseif ($selectedAction -match "Éditer") {
            Manage-InstanceEdit -Dir $installDir
        } elseif ($selectedAction -match "Hard Reset") {
            $hardReset = $true
        } elseif ($selectedAction -match "Delete") {
            Manage-InstanceDelete -Dir $installDir -Domain $existingDomain
        } elseif ($selectedAction -match "NOUVELLE instance") {
            $existingInstall = $false
            $createNewInstance = $true
            $installDir = ""
            $existingDomain = ""
        }
    }
}

if ($hardReset -and -not $nonInteractive) {
    $targetResetDomain = if (-not [string]::IsNullOrEmpty($existingDomain)) { $existingDomain } else { $domain }
    $expectedConfirmPhrase = "HARD RESET $targetResetDomain"

    Write-Host "`n  $escPink⚠️  ATTENTION : DEMANDE DE RÉINITIALISATION COMPLÈTE (HARD RESET)$escReset"
    Write-Host "  ${escMuted}Cible : $escBold$escWhite$targetResetDomain$escReset (Répertoire : $escCyan$installDir$escReset)$escReset"
    Write-Host "  $escPink Cette action est IRRÉVERSIBLE et va détruire définitivement :$escReset"
    Write-Host "    • Tous les dépôts Git hébergés et objets Git LFS de cette instance"
    Write-Host "    • La base PostgreSQL (utilisateurs, comptes, clés SSH, tickets)"
    Write-Host "    • Les conteneurs et volumes Docker associés à cette instance"
    Write-Host "    • La configuration de production .env.docker actuelle`n"

    $confirmOpts = @(
        "Non, annuler et conserver mes données intactes (Recommandé)",
        "Oui, je comprends les risques et souhaite réinitialiser"
    )
    $confirm1 = Gum-Choose -Title "Confirmation 1/2 : Êtes-vous sûr de vouloir supprimer TOUTES les données de $targetResetDomain ?" -Options $confirmOpts
    if ($confirm1 -ne 1) {
        Write-Host "  $escGreen✔$escReset $escBold${escWhite}Réinitialisation annulée. Vos données sont préservées intactes.$escReset`n"
        $hardReset = $false
        exit 0
    } else {
        $resetConfirm = Gum-Input -PromptLabel "Confirmation 2/2 : Tapez '$expectedConfirmPhrase' pour confirmer" -DefaultValue "" -Placeholder $expectedConfirmPhrase
        if ($resetConfirm -eq $expectedConfirmPhrase) {
            $hardReset = $true
            Write-Host "  $escPink✔$escReset $escBold${escWhite}Double confirmation validée. Hard reset enclenché pour $targetResetDomain.$escReset`n"
        } else {
            Write-Host "  $escGreen✔$escReset $escBold${escWhite}Saisie non conforme ('$resetConfirm' != '$expectedConfirmPhrase'). Réinitialisation annulée, données préservées.$escReset`n"
            $hardReset = $false
            exit 0
        }
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

    if ([string]::IsNullOrEmpty($installDir)) {
        $installDir = Join-Path $HOME "gickers.sh"
    }
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
    $existingEnvPath = Join-Path $installDir ".env.docker"
    if ((-not $hardReset) -and (Test-Path $existingEnvPath)) {
        $prevSecret = (Get-Content $existingEnvPath | Where-Object { $_ -match '^APP_SECRET=(.+)' } | ForEach-Object { $matches[1] } | Select-Object -First 1)
        $prevPass = (Get-Content $existingEnvPath | Where-Object { $_ -match '^POSTGRES_PASSWORD=(.+)' } | ForEach-Object { $matches[1] } | Select-Object -First 1)
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
    Invoke-QuietNative { docker compose --env-file .env.docker down -v --remove-orphans } | Out-Null
    Invoke-QuietNative { docker compose down -v --remove-orphans } | Out-Null
    Invoke-QuietNative { docker volume rm -f gickers-sh_gickers-data gickers-sh_gickers-db gickers-sh_gickers-var gickers-sh_gickers-deploy-engine } | Out-Null
    if (Test-Path ".env.docker") {
        Move-Item ".env.docker" ".env.docker.reset.bak" -Force -ErrorAction SilentlyContinue
    }
    Remove-Item -Recurse -Force ".data", "var/cache", "var/log" -ErrorAction SilentlyContinue
    $appSecret = ""
    $postgresPassword = ""
    $adminClaimToken = ""
    $adminSshKey = ""
    Write-Host "  $escGreen✔$escReset $escBold${escWhite}Hard reset terminé. L'environnement est maintenant vierge.$escReset`n"
}

$deployLog = Join-Path $env:TEMP "gickers_deploy.log"

if (Test-Path ".git") {
    Run-LoggedStep -StepLabel "Mise à jour du projet gickers.sh vers la dernière version" -LogFile $deployLog -WorkDir $installDir -CommandBlock {
        git fetch --depth 1 origin main
        if ($LASTEXITCODE -ne 0) { throw "git fetch failed ($LASTEXITCODE)" }
        git reset --hard origin/main
        if ($LASTEXITCODE -ne 0) { throw "git reset failed ($LASTEXITCODE)" }
    }
} elseif (-not (Test-Path "compose.yaml")) {
    Run-LoggedStep -StepLabel "Téléchargement du projet gickers.sh" -LogFile $deployLog -WorkDir $installDir -CommandBlock {
        git clone --depth 1 https://github.com/gickerssh/gickers.sh.git .
    }
}

New-Item -ItemType Directory -Force -Path ".data", ".data/caddy/sites", "var", "var/cache", "var/log" | Out-Null

if ([string]::IsNullOrEmpty($appSecret)) {
    $appSecret = [System.Guid]::NewGuid().ToString("N")
}
if ([string]::IsNullOrEmpty($postgresPassword)) {
    $postgresPassword = [System.Guid]::NewGuid().ToString("N").Substring(0, 24)
}

$adminClaimToken = ""
if ([string]::IsNullOrEmpty($adminSshKey)) {
    $adminClaimToken = [System.Guid]::NewGuid().ToString("N")
}

if ((-not $hardReset) -and (Test-Path ".env.docker")) {
    $existingSecret = (Get-Content ".env.docker" | Where-Object { $_ -match '^APP_SECRET=(.+)' } | ForEach-Object { $matches[1] } | Select-Object -First 1)
    $existingPass = (Get-Content ".env.docker" | Where-Object { $_ -match '^POSTGRES_PASSWORD=(.+)' } | ForEach-Object { $matches[1] } | Select-Object -First 1)
    $existingToken = (Get-Content ".env.docker" | Where-Object { $_ -match '^SOFT_SERVE_INITIAL_ADMIN_TOKEN=(.+)' } | ForEach-Object { $matches[1].Trim('"') } | Select-Object -First 1)
    $existingKeys = (Get-Content ".env.docker" | Where-Object { $_ -match '^SOFT_SERVE_INITIAL_ADMIN_KEYS=(.+)' } | ForEach-Object { $matches[1].Trim('"') } | Select-Object -First 1)
    if ($existingSecret) { $appSecret = $existingSecret }
    if ($existingPass) { $postgresPassword = $existingPass }
    if ($existingToken -and [string]::IsNullOrEmpty($adminSshKey)) { $adminClaimToken = $existingToken }
    if ($existingKeys -and [string]::IsNullOrEmpty($adminSshKey)) { $adminSshKey = $existingKeys }
}

$sshUrl = if ($sshPort -eq "22") { "ssh://$domain" } else { "ssh://${domain}:${sshPort}" }

$envContent = @"
# Production Environment for Gickers.sh (Domain: $domain)
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

Write-Utf8NoBomFile -Path (Join-Path (Get-Location).Path ".env.docker") -Content ($envContent + "`n")

Write-Host "  $escGreen✔$escReset $escBold$escWhite Configuration .env.docker initialisée.$escReset"

Run-LoggedStep -StepLabel "Construction des conteneurs Docker (PHP, gickers-ssh, FrankenPHP)" -LogFile $deployLog -WorkDir $installDir -CommandBlock {
    docker compose --env-file .env.docker build
}
Run-LoggedStep -StepLabel "Démarrage de PostgreSQL" -LogFile $deployLog -WorkDir $installDir -CommandBlock {
    docker compose --env-file .env.docker up -d postgres
}

Start-Sleep -Seconds 3
Run-LoggedStep -StepLabel "Exécution des migrations Doctrine & initialisation du serveur" -LogFile $deployLog -WorkDir $installDir -CommandBlock {
    docker compose --env-file .env.docker run -T --rm init
}
Run-LoggedStep -StepLabel "Démarrage complet de la stack (HTTP, SSH, Worker, Deploy-Engine)" -LogFile $deployLog -WorkDir $installDir -CommandBlock {
    docker compose --env-file .env.docker up -d
}

Write-Host ""
$finalSshUrl = if ($sshPort -ne "22" -and -not [string]::IsNullOrEmpty($sshPort)) { "ssh://${domain}:${sshPort}" } else { "ssh://${domain}" }
$finalSshCmd = if ($sshPort -ne "22" -and -not [string]::IsNullOrEmpty($sshPort)) { "ssh -p $sshPort $domain" } else { "ssh $domain" }

Write-Host "  $escBold${escGreen}✓ Installation et déploiement de $appName terminés avec succès !$escReset"
Write-Host "  ${escMuted}Votre forge Git et plateforme de conteneurs est opérationnelle :$escReset"
Write-Host "    • Interface Web :   https://$domain"
Write-Host "    • Clone SSH :       git clone $finalSshUrl/<user>/<repo>.git"
Write-Host "    • Clone HTTP :      https://$domain/<user>/<repo>.git"
Write-Host "    • Accès CLI / TUI : $finalSshCmd"
Write-Host "    • Info serveur :    $finalSshCmd info`n"

if (-not [string]::IsNullOrEmpty($adminClaimToken)) {
    $sshPortOpt = if ($sshPort -ne "22") { "-p $sshPort " } else { "" }
    Write-Host "  $escBold${escGreen}🔑 Initialisation Administrateur (Token sécurisé à usage unique)$escReset"
    Write-Host "  ${escMuted}Initialisez votre compte administrateur depuis votre poste client :$escReset`n"
    Write-Host "    $escCyan${escBold}ssh $sshPortOpt$domain signup --token $adminClaimToken | iex$escReset`n"
    Write-Host "  ${escMuted}Sur Linux / macOS (Bash) :$escReset"
    Write-Host "    $escCyan${escBold}ssh $sshPortOpt$domain signup --token $adminClaimToken | bash$escReset`n"
}
