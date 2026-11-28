# ==============================================================================
# gickers.sh SSH Configuration & Onboarding Wizard (Windows PowerShell)
# Inspired by Charmbracelet Gum (TokyoNight High Contrast Theme)
# Generated for @__USERNAME__ on __HOST__
# ==============================================================================
$ErrorActionPreference = "Stop"

__GUM_LIB__

# Display Termstrap Header Card
@'
__HEADER_CARD__
'@ | Write-Host

$hostName = '__HOST__'
$username = "__USERNAME__"
$portNum = '__PORT__'
$portOpt = '__PORT_OPT__'
$portArgs = if ($portNum -and $portNum -ne '22') { @('-p', $portNum) } else { @() }
$portLine = @'
__PORT_LINE__
'@
$defaultKey = "__DEFAULT_KEY_PATH__"
$keyPath = $defaultKey
$connectedKeyData = '__CONNECTED_KEY_DATA__'
$connectedFingerprint = '__CONNECTED_FINGERPRINT__'
$testCmd = "__TEST_CMD__"
$isSignup = "__IS_SIGNUP__"
$alreadyRegistered = "__ALREADY_REGISTERED__"
$claimToken = "__CLAIM_TOKEN__"

if ($alreadyRegistered -eq "0") {
    $defaultUser = if ($username) { $username } else { $env:USERNAME }
    if (-not $username) {
        while ($true) {
            $username = Gum-Input -PromptLabel "Choisissez votre nom d'utilisateur gickers.sh" -DefaultValue $defaultUser
            $username = $username.Trim()
            if ($username -notmatch '^[a-zA-Z0-9._-]{2,32}$') {
                Write-Host "  $escPink✖$escReset $escBold$escPink Nom d'utilisateur invalide (2 à 32 caractères alphanumériques, '-' ou '_'). Réessayez :$escReset"
                continue
            }
            break
        }
    } else {
        Write-Host "  $escGreen✔$escReset $escBold$escWhite Nom d'utilisateur sélectionné$escReset : $escBadge $escBold$escCyan@$username $escReset"
    }
    $defaultKey = Join-Path $HOME ".ssh\gickers_sh_$username"
    $keyPath = $defaultKey
}

$sshDir = Join-Path $HOME ".ssh"
if (-not (Test-Path $sshDir)) {
    New-Item -ItemType Directory -Path $sshDir | Out-Null
}

$detectedKeyFile = ""
if (Test-Path $sshDir) {
    Get-ChildItem -Path $sshDir -Filter "*.pub" | ForEach-Object {
        $content = Get-Content -Path $_.FullName -Raw
        if ($content -and $connectedKeyData -and $content.Contains($connectedKeyData)) {
            $priv = $_.FullName -replace '\.pub$', ''
            if (Test-Path $priv) {
                $detectedKeyFile = $priv
            }
        }
    }
}

$optActive = if ($detectedKeyFile) {
    "Utiliser la clé active de cette session (Recommandé: $detectedKeyFile)"
} elseif ($connectedFingerprint) {
    "Utiliser la clé active de cette session (Empreinte: $connectedFingerprint)"
} else {
    "Utiliser la clé active de cette session"
}

$keyOptions = @(
    $optActive,
    "Générer une nouvelle clé dédiée ($defaultKey)",
    "Coller le contenu d'une clé publique existante (.pub)",
    "Conserver la clé par défaut du trousseau (agent global)"
)

$choice = Gum-Choose -Title "Gestion de votre clé SSH" -Options $keyOptions

if ($choice -eq 0) {
    if ($detectedKeyFile) {
        $keyPath = $detectedKeyFile
    } else {
        $defaultExisting = Join-Path $sshDir "id_ed25519"
        $keyPath = Gum-Input -PromptLabel "Chemin de votre clé privée active" -DefaultValue $defaultExisting
    }
} elseif ($choice -eq 1) {
    $algoOptions = @(
        "ed25519 (moderne, recommandé)",
        "rsa (4096 bits, compatibilité entreprise)",
        "ed25519-sk (clé physique YubiKey / FIDO2)"
    )
    $algoChoice = Gum-Choose -Title "Type d'algorithme" -Options $algoOptions
    $algo = "ed25519"
    $extraArgs = @()
    if ($algoChoice -eq 1) {
        $algo = "rsa"
        $extraArgs = @("-b", "4096")
    } elseif ($algoChoice -eq 2) {
        $algo = "ed25519-sk"
    }

    $curPath = $defaultKey
    $overwriteKey = $true
    while ($true) {
        $curPath = Gum-Input -PromptLabel "Chemin du fichier" -DefaultValue $curPath
        if (Test-Path $curPath) {
            Write-Host "`n  $escGold!$escReset $escBold$escWhite Le fichier $curPath existe déjà.$escReset"
            $colChoice = Gum-Choose -Title "Action souhaitée" -Options @(
                "Réutiliser cette clé existante",
                "Écraser et générer une nouvelle clé",
                "Choisir un autre chemin de fichier"
            )
            if ($colChoice -eq 0) {
                $keyPath = $curPath
                $overwriteKey = $false
                break
            } elseif ($colChoice -eq 1) {
                Remove-Item -Path $curPath -Force -ErrorAction SilentlyContinue
                Remove-Item -Path "$curPath.pub" -Force -ErrorAction SilentlyContinue
                $keyPath = $curPath
                $overwriteKey = $true
                break
            } else {
                $curPath = "${curPath}_new"
                continue
            }
        } else {
            $keyPath = $curPath
            $overwriteKey = $true
            break
        }
    }

    if ($overwriteKey) {
        $plainPass = Gum-PasswordWithConfirm -PromptLabel "Passphrase"

        Write-Host -NoNewline "  $escPink⠋$escReset $escBold$escWhite Génération de la clé SSH $algo...$escReset"
        & ssh-keygen.exe -t $algo @extraArgs -f $keyPath -C "$username@$hostName" -N $plainPass | Out-Null
        Write-Host "`r$esc[2K  $escGreen✔$escReset $escBold$escWhite Clé SSH $algo générée : $escGold$keyPath$escReset"

        $pubPath = "$keyPath.pub"
        if ((Test-Path $pubPath) -and ($alreadyRegistered -eq "1")) {
            $pubContent = (Get-Content -Path $pubPath -Raw).Trim()
            Write-Host -NoNewline "  $escPink⠋$escReset $escBold$escWhite Autorisation de la clé publique sur $hostName...$escReset"
            try {
                if ($detectedKeyFile) {
                    & ssh $portOpt -i "$detectedKeyFile" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new "$username@$hostName" pubkey add "$pubContent" | Out-Null
                } else {
                    & ssh $portOpt -o IdentitiesOnly=no -o StrictHostKeyChecking=accept-new "$username@$hostName" pubkey add "$pubContent" | Out-Null
                }
                Write-Host "`r$esc[2K  $escGreen✔$escReset $escBold$escWhite Clé autorisée sur votre compte $username@$hostName$escReset"
            } catch {
                Write-Host "`r$esc[2K  $escCyanℹ$escReset $escMuted Clé publique enregistrée$escReset"
            }
        }
    } else {
        Write-Host "  $escCyanℹ$escReset $escMuted Clé existante réutilisée : $escGold$keyPath$escReset"
    }
} elseif ($choice -eq 2) {
    # Paste public key
    $pastedKey = Gum-PastePubkey
    $pastedParts = $pastedKey -split '\s+'
    $pastedData = if ($pastedParts.Length -ge 2) { $pastedParts[1] } else { "" }
    $detectedPriv = ""
    if ($pastedData -and (Test-Path $sshDir)) {
        Get-ChildItem -Path $sshDir -Filter "*.pub" | ForEach-Object {
            $c = Get-Content -Path $_.FullName -Raw
            if ($c -and $c.Contains($pastedData)) {
                $p = $_.FullName -replace '\.pub$', ''
                if (Test-Path $p) { $detectedPriv = $p }
            }
        }
    }
    if ($detectedPriv) {
        $keyPath = $detectedPriv
        Write-Host "  $escGreen✔$escReset $escBold$escWhite Clé privée locale associée$escReset : $escBadge $escGold$keyPath $escReset"
    } else {
        $keyPath = ""
        Write-Host "  $escCyanℹ$escReset $escMuted Authentification configurée via votre agent SSH global$escReset"
    }

    if ($alreadyRegistered -eq "1") {
        Write-Host -NoNewline "  $escPink⠋$escReset $escBold$escWhite Autorisation de la clé collée sur $hostName...$escReset"
        try {
            if ($detectedKeyFile) {
                & ssh $portOpt -i "$detectedKeyFile" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new "$username@$hostName" pubkey add "$pastedKey" | Out-Null
            } else {
                & ssh $portOpt -o IdentitiesOnly=no -o StrictHostKeyChecking=accept-new "$username@$hostName" pubkey add "$pastedKey" | Out-Null
            }
            Write-Host "`r$esc[2K  $escGreen✔$escReset $escBold$escWhite Clé autorisée sur votre compte $username@$hostName$escReset"
        } catch {
            Write-Host "`r$esc[2K  $escCyanℹ$escReset $escMuted Clé publique enregistrée$escReset"
        }
    }
} else {
    $keyPath = ""
}

# Account registration on gickers.sh for new signups
if ($alreadyRegistered -eq "0") {
    $regSuccess = $false
    while (-not $regSuccess) {
        Write-Host -NoNewline "  $escPink⠋$escReset $escBold$escWhite Enregistrement de votre compte @$username sur $hostName...$escReset"
        try {
            $tokenArgs = if ($claimToken) { @('--token', $claimToken) } else { @() }
            if ($choice -eq 1 -and (Test-Path "$keyPath.pub")) {
                $pubContent = (Get-Content "$keyPath.pub" -Raw).Trim()
                $out = & ssh $portOpt -o IdentitiesOnly=no -o StrictHostKeyChecking=accept-new $hostName signup $username --register @tokenArgs -k "$pubContent" 2>&1
            } elseif ($choice -eq 2 -and $pastedKey) {
                $out = & ssh $portOpt -o IdentitiesOnly=no -o StrictHostKeyChecking=accept-new $hostName signup $username --register @tokenArgs -k "$pastedKey" 2>&1
            } elseif ($keyPath -and (Test-Path $keyPath)) {
                $out = & ssh $portOpt -i "$keyPath" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new $hostName signup $username --register @tokenArgs 2>&1
            } else {
                $out = & ssh $portOpt -o IdentitiesOnly=no -o StrictHostKeyChecking=accept-new $hostName signup $username --register @tokenArgs 2>&1
            }
            if ($LASTEXITCODE -eq 0) {
                $regSuccess = $true
                Write-Host "`r$esc[2K  $escGreen✔$escReset $escBold$escWhite Compte @$username créé avec succès sur $hostName !$escReset"
            } else {
                Write-Host "`r$esc[2K  $escPink✖$escReset $escBold$escPink Échec de l'enregistrement : $out$escReset"
                $username = (Gum-Input -PromptLabel "Choisissez un autre nom d'utilisateur").Trim()
            }
        } catch {
            Write-Host "`r$esc[2K  $escPink✖$escReset $escBold$escPink Erreur : $_$escReset"
            $username = (Gum-Input -PromptLabel "Choisissez un autre nom d'utilisateur").Trim()
        }
    }
    $testCmd = "ssh$portOpt $username@$hostName info"
}

$configFile = Join-Path $sshDir "config"
if (-not (Test-Path $configFile)) {
    New-Item -ItemType File -Path $configFile | Out-Null
}

$optKeys = @("IdentitiesOnly", "RequestTTY", "SendEnv", "ServerAliveInterval", "ServerAliveCountMax")
$optLines = @(
    "    IdentitiesOnly yes",
    "    RequestTTY auto",
    "    SendEnv COLUMNS LINES TERM COLORTERM",
    "    ServerAliveInterval 30",
    "    ServerAliveCountMax 3"
)

$configLines = if (Test-Path $configFile) { Get-Content -Path $configFile } else { @() }
$targetHostExists = $false
$inTargetBlock = $false
$foundKeys = [System.Collections.Generic.List[string]]::new()

foreach ($line in $configLines) {
    if ($line -match '^\s*#') { continue }
    if ($line -match "^\s*Host\s+.*(\s|^)$([regex]::Escape($hostName))(\s|`$|,)") {
        $targetHostExists = $true
        $inTargetBlock = $true
        continue
    }
    if ($inTargetBlock) {
        if ($line -match '^\s*(Host|Match)\s+') {
            $inTargetBlock = $false
            continue
        }
        foreach ($k in $optKeys) {
            if ($k -eq "SendEnv") {
                if ($line -match '^\s*SendEnv\s+.*COLUMNS' -and $line -match 'TERM' -and $line -match 'COLORTERM') {
                    $foundKeys.Add($k)
                }
            } elseif ($line -match "^\s*$k\s+") {
                $foundKeys.Add($k)
            }
        }
    }
}

if (-not $targetHostExists) {
    $baseBlock = @"

# Base configuration for $hostName
Host $hostName
    HostName $hostName
$portLine    IdentitiesOnly yes
    RequestTTY auto
    SendEnv COLUMNS LINES TERM COLORTERM
    ServerAliveInterval 30
    ServerAliveCountMax 3
"@
    Add-Content -Path $configFile -Value $baseBlock -Encoding UTF8
    Write-Host "  $escGreen✔$escReset $escBold$escWhite Configuration SSH $configFile initialisée$escReset"
} else {
    $missingLines = [System.Collections.Generic.List[string]]::new()
    for ($i = 0; $i -lt $optKeys.Count; $i++) {
        if (-not $foundKeys.Contains($optKeys[$i])) {
            $missingLines.Add($optLines[$i])
        }
    }

    if ($missingLines.Count -gt 0) {
        $newLines = [System.Collections.Generic.List[string]]::new()
        $inserted = $false
        foreach ($line in $configLines) {
            $newLines.Add($line)
            if (-not $inserted -and ($line -notmatch '^\s*#') -and ($line -match "^\s*Host\s+.*(\s|^)$([regex]::Escape($hostName))(\s|`$|,)")) {
                foreach ($m in $missingLines) {
                    $newLines.Add($m)
                }
                $inserted = $true
            }
        }
        Set-Content -Path $configFile -Value $newLines -Encoding UTF8
        Write-Host "  $escGreen✔$escReset $escBold$escWhite Configuration SSH $configFile optimisée$escReset $escMuted($($missingLines.Count) option(s) ajoutée(s))$escReset"
    } else {
        Write-Host "  $escGreen✔$escReset $escBold$escWhite Configuration SSH $configFile déjà optimisée$escReset"
    }
}

if (-not [string]::IsNullOrWhiteSpace($keyPath)) {
    $activeLines = ($configLines | Where-Object { $_ -notmatch '^\s*#' })
    $hasProfile = $false
    foreach ($line in $activeLines) {
        if ($line -match '^\s*Match\s+' -and $line -match '\bhost\s+[^\n]*\b__HOST__\b' -and $line -match '\buser\s+[^\n]*\b__USERNAME__\b') {
            $hasProfile = $true
            break
        }
    }

    if (-not $hasProfile) {
        $cleanKeyPath = $keyPath -replace '\\', '/'
        $matchBlock = @"

# Account profile for @$username
Match host $hostName user $username
    IdentityFile $cleanKeyPath
"@
        Add-Content -Path $configFile -Value $matchBlock -Encoding UTF8
        Write-Host "  $escGreen✔$escReset $escBold$escWhite Profil utilisateur @$username lié à $escGold$cleanKeyPath$escReset"
    } else {
        $inMatch = $false
        $hasIdentity = $false
        foreach ($line in $configLines) {
            if ($line -match '^\s*#') { continue }
            if ($line -match '^\s*Match\s+' -and $line -match "\bhost\s+[^\n]*\b$([regex]::Escape($hostName))\b" -and $line -match "\buser\s+[^\n]*\b$([regex]::Escape($username))\b") {
                $inMatch = $true
                continue
            }
            if ($inMatch) {
                if ($line -match '^\s*(Host|Match)\s+') {
                    $inMatch = $false
                    continue
                }
                if ($line -match '^\s*IdentityFile\s+') {
                    $hasIdentity = $true
                    break
                }
            }
        }

        if (-not $hasIdentity) {
            $cleanKeyPath = $keyPath -replace '\\', '/'
            $newLines = [System.Collections.Generic.List[string]]::new()
            $inserted = $false
            foreach ($line in $configLines) {
                $newLines.Add($line)
                if (-not $inserted -and ($line -notmatch '^\s*#') -and ($line -match '^\s*Match\s+') -and ($line -match "\bhost\s+[^\n]*\b$([regex]::Escape($hostName))\b") -and ($line -match "\buser\s+[^\n]*\b$([regex]::Escape($username))\b")) {
                    $newLines.Add("    IdentityFile $cleanKeyPath")
                    $inserted = $true
                }
            }
            Set-Content -Path $configFile -Value $newLines -Encoding UTF8
            Write-Host "  $escGreen✔$escReset $escBold$escWhite IdentityFile lié à $escGold$cleanKeyPath$escWhite ajouté au profil @$username$escReset"
        } else {
            Write-Host "  $escGreen✔$escReset $escBold$escWhite Profil utilisateur @$username déjà configuré$escReset"
        }
    }
}

try {
    icacls.exe $configFile /inheritance:r /grant:r "$($env:USERNAME):(R,W)" | Out-Null
    if (-not [string]::IsNullOrWhiteSpace($keyPath) -and (Test-Path $keyPath)) {
        icacls.exe $keyPath /inheritance:r /grant:r "$($env:USERNAME):(R,W)" | Out-Null
    }
} catch {
    # Ignore permission errors if not supported
}

# 3. Shell integration (export COLUMNS LINES for PowerShell $PROFILE)
try {
    if ($PROFILE) {
        $profileDir = Split-Path -Parent $PROFILE
        if (-not (Test-Path $profileDir)) {
            New-Item -ItemType Directory -Path $profileDir -Force | Out-Null
        }
        if (-not (Test-Path $PROFILE)) {
            New-Item -ItemType File -Path $PROFILE -Force | Out-Null
        }
        $profileContent = Get-Content -Path $PROFILE -Raw -ErrorAction SilentlyContinue
        if ($profileContent -match '\$env:COLUMNS') {
            Write-Host "  $escGreen✔$escReset $escBold$escWhite Variables COLUMNS/LINES déjà configurées dans $PROFILE$escReset"
        } else {
            $exportBlock = @"

# Export terminal dimensions for SSH CLI tools (gickers.sh SendEnv)
`$env:COLUMNS = `$Host.UI.RawUI.WindowSize.Width
`$env:LINES = `$Host.UI.RawUI.WindowSize.Height
"@
            Add-Content -Path $PROFILE -Value $exportBlock -Encoding UTF8
            Write-Host "  $escGreen✔$escReset $escBold$escWhite Export automatique COLUMNS/LINES configuré dans $PROFILE$escReset"
        }
    }
} catch {
    # Ignore profile write errors in restricted environments
}

Write-Host -NoNewline "  $escPink⠋$escReset $escBold$escWhite Vérification de la connexion vers $hostName...$escReset"
try {
    & $testCmd | Out-Null
    Write-Host "`r$esc[2K  $escGreen✔$escReset $escBold$escWhite Connexion vers $hostName vérifiée avec succès$escReset"
} catch {
    Write-Host "`r$esc[2K  $escCyanℹ$escReset $escMuted Connexion prête ($testCmd)$escReset"
}

# 5. [Facultatif] Initialisation du mot de passe de secours
$setPwChoice = Read-Host "Souhaitez-vous configurer un mot de passe de secours pour @$username ? (o/N)"
if ($setPwChoice -match '^(o|oui|y|yes)$') {
    $plainPw = ""
    while ($true) {
        $secPw1 = Read-Host "Mot de passe de secours (min 6 caractères)" -AsSecureString
        $plain1 = [System.Net.NetworkCredential]::new('', $secPw1).Password
        if ([string]::IsNullOrWhiteSpace($plain1)) {
            break
        }
        if ($plain1.Length -lt 6) {
            Write-Host "  $escPink✖$escReset $escBold$escPink Le mot de passe doit comporter au moins 6 caractères. Veuillez réessayer.$escReset"
            continue
        }
        $secPw2 = Read-Host "Confirmation du mot de passe" -AsSecureString
        $plain2 = [System.Net.NetworkCredential]::new('', $secPw2).Password
        if ($plain1 -ne $plain2) {
            Write-Host "  $escPink✖$escReset $escBold$escPink Les mots de passe ne correspondent pas. Veuillez réessayer.$escReset"
            continue
        }
        $plainPw = $plain1
        break
    }

    if (-not [string]::IsNullOrWhiteSpace($plainPw)) {
        try {
            $plainPw | ssh $portOpt "$userName@$hostName" user password --stdin | Out-Null
            Write-Host "  $escGreen✔$escReset $escBold$escWhite Mot de passe de secours enregistré pour @$username$escReset"
            Write-Host "    $escMuted En cas de perte de clé, restaurez votre accès avec : $escCyan ssh$portOpt $userName@$hostName recover$escReset"
        } catch {
            Write-Host "  $escGold!$escReset $escMuted Impossible d'enregistrer le mot de passe via SSH$escReset"
        }
    }
}

Write-Host ""
@'
__SUCCESS_CARD__
'@ | Write-Host
Write-Host ""
