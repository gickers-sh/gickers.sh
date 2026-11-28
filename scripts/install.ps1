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

function Run-LoggedStep {
    param(
        [string]$StepLabel,
        [string]$LogFile,
        [string]$WorkDir,
        [scriptblock]$CommandBlock
    )

    $chars = @('⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏')
    $sw = [System.Diagnostics.Stopwatch]::StartNew()

    while ($true) {
        $sw.Restart()
        $prevPwd = (Get-Location).Path
        if (-not [string]::IsNullOrEmpty($WorkDir) -and (Test-Path $WorkDir)) {
            Set-Location $WorkDir
        }

        $cmdStr = $CommandBlock.ToString()
        $isBuild = $cmdStr -match "docker\s+compose.*build"

        $exitCode = 0
        if ($isBuild) {
            Write-Host "  $escPink⠋$escReset $escBold$escWhite$StepLabel...$escReset"
            try {
                & $CommandBlock 2>&1 | Tee-Object -FilePath $LogFile
                $exitCode = $LASTEXITCODE
            } catch {
                $exitCode = 1
                $_ | Out-File -FilePath $LogFile -Append
            }
        } else {
            Write-Host -NoNewline "$esc[?25l"
            $job = Start-Job -ScriptBlock {
                param($blk, $dir, $log)
                Set-Location $dir
                try {
                    & $blk 2>&1 | Out-File -FilePath $log -Encoding UTF8
                    return $LASTEXITCODE
                } catch {
                    $_ | Out-File -FilePath $log -Append -Encoding UTF8
                    return 1
                }
            } -ArgumentList $CommandBlock, (Get-Location).Path, $LogFile

            $i = 0
            while ($job.State -eq 'Running') {
                $elapsedSec = [math]::Floor($sw.Elapsed.TotalSeconds)
                $timeStr = if ($elapsedSec -ge 60) {
                    "$([math]::Floor($elapsedSec / 60))m $(($elapsedSec % 60).ToString('D2'))s"
                } else {
                    "${elapsedSec}s"
                }
                Write-Host -NoNewline "`r  $escPink$($chars[$i])$escReset $escBold$escWhite$StepLabel...$escReset $escDim[$timeStr]$escReset$esc[K"
                $i = ($i + 1) % 10
                Start-Sleep -Milliseconds 80
            }

            Write-Host -NoNewline "$esc[?25h"
            $null = Receive-Job -Job $job
            $exitCode = $job.ChildJobs[0].ExitCode
            if ($null -eq $exitCode) { $exitCode = 0 }
            Remove-Job -Job $job -Force
        }

        Set-Location $prevPwd
        $sw.Stop()
        $elapsedSec = [math]::Floor($sw.Elapsed.TotalSeconds)
        $timeStr = if ($elapsedSec -ge 60) {
            "$([math]::Floor($elapsedSec / 60))m $(($elapsedSec % 60).ToString('D2'))s"
        } else {
            "${elapsedSec}s"
        }

        if ($exitCode -eq 0) {
            Write-Host "`r$esc[2K  $escGreen✔$escReset $escBold$escWhite$StepLabel...$escReset $escMuted($timeStr)$escReset"
            return 0
        }

        Write-Host "`r$esc[2K  $escPink✖$escReset $escBold$escPink$StepLabel ÉCHOUÉ$escReset $escDim(Code: $exitCode, Durée: $timeStr)$escReset`n"
        Write-Host "  $escBold${escCyan}Dernières lignes du journal ($LogFile) :$escReset"
        if (Test-Path $LogFile) {
            Get-Content -Path $LogFile -Tail 35 | ForEach-Object {
                Write-Host "    $escDim$_$escReset"
            }
        }
        Write-Host ""

        $errOpts = @(
            "Réessayer cette étape maintenant (Recommandé)",
            "Afficher l'intégralité du journal de log",
            "Ignorer cette étape et continuer quand même",
            "Abandonner l'installation"
        )
        $errChoice = Gum-Choose -Title "Action en cas d'erreur sur '$StepLabel'" -Options $errOpts
        switch ($errChoice) {
            0 { continue }
            1 {
                if (Test-Path $LogFile) {
                    Get-Content -Path $LogFile | Out-Host -Paging
                }
                continue
            }
            2 {
                Write-Host "  $escGold!$escReset $escBold${escWhite}Étape ignorée par l'administrateur.$escReset`n"
                return 0
            }
            3 {
                Write-Host "`n  $escPink✖$escReset $escBold${escPink}Installation interrompue par l'administrateur.$escReset`n"
                exit $exitCode
            }
        }
    }
}

function Manage-InstanceUpdate {
    param([string]$Dir)
    $logFile = Join-Path $env:TEMP "gickers_update.log"
    Write-Host "`n  $escBold${escCyan}🔄 Mise à jour de l'instance gickers.sh : $Dir$escReset`n"

    if (Test-Path (Join-Path $Dir ".git")) {
        Run-LoggedStep -StepLabel "Récupération du dernier code source (git pull)" -LogFile $logFile -WorkDir $Dir -CommandBlock {
            git fetch --depth 1 origin main 2>$null
            git reset --hard origin/main 2>$null
        }
    }

    if (Test-Path (Join-Path $Dir "compose.yaml")) {
        Run-LoggedStep -StepLabel "Construction / Mise à jour des images Docker" -LogFile $logFile -WorkDir $Dir -CommandBlock {
            docker compose --env-file .env.docker build
        }
        Run-LoggedStep -StepLabel "Application des migrations Doctrine" -LogFile $logFile -WorkDir $Dir -CommandBlock {
            docker compose --env-file .env.docker run -T --rm init
        }
        Run-LoggedStep -StepLabel "Redémarrage de la stack applicative" -LogFile $logFile -WorkDir $Dir -CommandBlock {
            docker compose --env-file .env.docker up -d
        }
    }

    Write-Host "  $escGreen✔$escReset $escBold${escWhite}Instance mise à jour avec succès.$escReset`n"
    exit 0
}

function Manage-InstanceRestart {
    param([string]$Dir)
    $logFile = Join-Path $env:TEMP "gickers_restart.log"
    Write-Host "`n  $escBold${escCyan}🔁 Redémarrage des services : $Dir$escReset`n"

    if (Test-Path (Join-Path $Dir "compose.yaml")) {
        Run-LoggedStep -StepLabel "Redémarrage des conteneurs Docker" -LogFile $logFile -WorkDir $Dir -CommandBlock {
            docker compose --env-file .env.docker restart
        }
    }

    Write-Host "  $escGreen✔$escReset $escBold${escWhite}Services redémarrés avec succès.$escReset`n"
    exit 0
}

function Manage-InstanceRebuild {
    param([string]$Dir)
    $logFile = Join-Path $env:TEMP "gickers_rebuild.log"
    Write-Host "`n  $escBold${escCyan}🏗️  Reconstruction complète des conteneurs : $Dir$escReset`n"

    if (Test-Path (Join-Path $Dir "compose.yaml")) {
        Run-LoggedStep -StepLabel "Reconstruction des images Docker (--no-cache)" -LogFile $logFile -WorkDir $Dir -CommandBlock {
            docker compose --env-file .env.docker build --no-cache
        }
        Run-LoggedStep -StepLabel "Redémarrage des conteneurs avec les nouvelles images" -LogFile $logFile -WorkDir $Dir -CommandBlock {
            docker compose --env-file .env.docker up -d --force-recreate
        }
    }

    Write-Host "  $escGreen✔$escReset $escBold${escWhite}Images reconstruites et conteneurs relancés avec succès.$escReset`n"
    exit 0
}

function Manage-InstanceRedeploy {
    param([string]$Dir)
    $logFile = Join-Path $env:TEMP "gickers_redeploy.log"
    Write-Host "`n  $escBold${escCyan}🚀 Redéploiement (cache, migrations & stack) : $Dir$escReset`n"

    if (Test-Path (Join-Path $Dir "compose.yaml")) {
        Run-LoggedStep -StepLabel "Exécution des migrations & warmup cache (init)" -LogFile $logFile -WorkDir $Dir -CommandBlock {
            docker compose --env-file .env.docker run -T --rm init
        }
        Run-LoggedStep -StepLabel "Redéploiement de la stack" -LogFile $logFile -WorkDir $Dir -CommandBlock {
            docker compose --env-file .env.docker up -d
        }
    }

    Write-Host "  $escGreen✔$escReset $escBold${escWhite}Redéploiement terminé avec succès.$escReset`n"
    exit 0
}

function Manage-InstanceEdit {
    param([string]$Dir)
    $envFile = Join-Path $Dir ".env.docker"
    if (-not (Test-Path $envFile)) { $envFile = Join-Path $Dir ".env.local" }
    if (-not (Test-Path $envFile)) {
        Write-Host "  $escPink✖$escReset $escBold${escPink}Fichier de configuration introuvable dans $Dir$escReset`n"
        exit 1
    }

    Write-Host "`n  $escBold${escCyan}⚙️  Édition de la configuration : $envFile$escReset`n"

    $curDomain = (Get-Content $envFile | Where-Object { $_ -match '^APP_DOMAIN=(.+)' } | ForEach-Object { $matches[1].Trim(' "') })
    if ([string]::IsNullOrEmpty($curDomain)) { $curDomain = "gits.run" }
    $curSsh = (Get-Content $envFile | Where-Object { $_ -match '^SSH_PORT=(.+)' } | ForEach-Object { $matches[1].Trim(' "') })
    if ([string]::IsNullOrEmpty($curSsh)) { $curSsh = "22" }
    $curHttp = (Get-Content $envFile | Where-Object { $_ -match '^HTTP_PORT=(.+)' } | ForEach-Object { $matches[1].Trim(' "') })
    if ([string]::IsNullOrEmpty($curHttp)) { $curHttp = "80" }
    $curHttps = (Get-Content $envFile | Where-Object { $_ -match '^HTTPS_PORT=(.+)' } | ForEach-Object { $matches[1].Trim(' "') })
    if ([string]::IsNullOrEmpty($curHttps)) { $curHttps = "443" }

    $newDomain = Gum-Input -PromptLabel "Domaine public (FQDN)" -DefaultValue $curDomain
    $newSsh = Gum-Input -PromptLabel "Port SSH public" -DefaultValue $curSsh
    $newHttp = Gum-Input -PromptLabel "Port HTTP public" -DefaultValue $curHttp
    $newHttps = Gum-Input -PromptLabel "Port HTTPS public" -DefaultValue $curHttps

    $signOpts = @("Inscriptions ouvertes (true)", "Inscriptions fermées (false)")
    $signChoice = Gum-Choose -Title "Politique d'inscriptions" -Options $signOpts
    $newSignup = if ($signChoice -eq 1) { "false" } else { "true" }

    $lines = Get-Content $envFile
    $newLines = @()
    foreach ($line in $lines) {
        if ($line -match '^APP_DOMAIN=') { $newLines += "APP_DOMAIN=$newDomain" }
        elseif ($line -match '^SSH_PORT=') { $newLines += "SSH_PORT=$newSsh" }
        elseif ($line -match '^HTTP_PORT=') { $newLines += "HTTP_PORT=$newHttp" }
        elseif ($line -match '^HTTPS_PORT=') { $newLines += "HTTPS_PORT=$newHttps" }
        elseif ($line -match '^SOFT_SERVE_ALLOW_SIGNUP=') { $newLines += "SOFT_SERVE_ALLOW_SIGNUP=$newSignup" }
        elseif ($line -match '^SOFT_SERVE_SSH_PUBLIC_URL=') { $newLines += "SOFT_SERVE_SSH_PUBLIC_URL=ssh://$newDomain" }
        elseif ($line -match '^SOFT_SERVE_HTTP_PUBLIC_URL=') { $newLines += "SOFT_SERVE_HTTP_PUBLIC_URL=https://$newDomain" }
        else { $newLines += $line }
    }
    Set-Content -Path $envFile -Value $newLines -Encoding UTF8

    Write-Host "`n  $escGreen✔$escReset $escBold${escWhite}Configuration mise à jour dans $envFile$escReset"

    if (Test-Path (Join-Path $Dir "compose.yaml")) {
        $logFile = Join-Path $env:TEMP "gickers_edit_apply.log"
        Run-LoggedStep -StepLabel "Application de la nouvelle configuration aux conteneurs" -LogFile $logFile -WorkDir $Dir -CommandBlock {
            docker compose --env-file .env.docker up -d
        }
    }

    Write-Host "  $escGreen✔$escReset $escBold${escWhite}Modifications appliquées avec succès.$escReset`n"
    exit 0
}

function Manage-InstanceDelete {
    param(
        [string]$Dir,
        [string]$Domain
    )
    if ([string]::IsNullOrEmpty($Domain)) { $Domain = "cette instance" }
    $expectedConfirm = "DELETE $Domain"

    Write-Host "`n  $escPink⚠️  ATTENTION : DEMANDE DE SUPPRESSION DÉFINITIVE (DELETE)$escReset"
    Write-Host "  ${escMuted}Cible : $escBold$escWhite$Domain$escReset (Répertoire : $escCyan$Dir$escReset)$escReset"
    Write-Host "  $escPink Cette action est IRRÉVERSIBLE et va détruire définitivement :$escReset"
    Write-Host "    • Tous les conteneurs, réseaux et volumes Docker de cette instance"
    Write-Host "    • La base de données PostgreSQL, les dépôts Git et configurations"
    Write-Host "    • Le dossier d'installation $Dir sur la machine`n"

    $confirmOpts = @(
        "Non, annuler et conserver mon instance intacte (Recommandé)",
        "Oui, je veux détruire et supprimer définitivement cette instance"
    )
    $c1 = Gum-Choose -Title "Confirmation 1/2 : Êtes-vous certain de vouloir supprimer cette instance ?" -Options $confirmOpts
    if ($c1 -ne 1) {
        Write-Host "  $escGreen✔$escReset $escBold${escWhite}Suppression annulée. L'instance reste intacte.$escReset`n"
        exit 0
    }

    $delConfirm = Gum-Input -PromptLabel "Confirmation 2/2 : Tapez '$expectedConfirm' pour confirmer" -DefaultValue "" -Placeholder $expectedConfirm
    if ($delConfirm -ne $expectedConfirm) {
        Write-Host "  $escGreen✔$escReset $escBold${escWhite}Saisie non conforme. Suppression annulée, données préservées.$escReset`n"
        exit 0
    }

    Write-Host "`n  $escPink🗑️  Suppression définitive en cours pour $Domain...$escReset"
    $logFile = Join-Path $env:TEMP "gickers_delete_docker.log"

    if (Test-Path (Join-Path $Dir "compose.yaml")) {
        Run-LoggedStep -StepLabel "Arrêt et destruction des conteneurs et volumes Docker" -LogFile $logFile -WorkDir $Dir -CommandBlock {
            docker compose --env-file .env.docker down -v --remove-orphans 2>$null
            docker compose down -v --remove-orphans 2>$null
        }
    }

    docker volume rm -f gickers-sh_gickers-data gickers-sh_gickers-db gickers-sh_gickers-var gickers-sh_gickers-deploy-engine 2>$null

    if (Test-Path $Dir) {
        $logFileFs = Join-Path $env:TEMP "gickers_delete_fs.log"
        Run-LoggedStep -StepLabel "Suppression du répertoire $Dir" -LogFile $logFileFs -WorkDir (Get-Location).Path -CommandBlock {
            Remove-Item -Recurse -Force -Path $Dir
        }
    }

    Write-Host "  $escGreen✔$escReset $escBold${escWhite}Instance $Domain supprimée définitivement avec succès. Ports et ressources libérés.$escReset`n"
    exit 0
}



# Display Termstrap Header
@'
🚀 Gickers.sh — Installation & Déploiement One-Shot [Production VPS &     
Local]                                                                    
[38;2;86;95;137m[38;2;86;95;137mCible : codes.sh • Git Forge & Containers Isolés • Caddy SSL Auto[m[m         
                                                                          
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
    try {
        $dockerDirs = docker ps -a --filter "ancestor=gickers-sh:latest" --format '{{ index .Labels "com.docker.compose.project.working_dir" }}' 2>$null
        if ($dockerDirs) {
            foreach ($d in ($dockerDirs -split "`r?`n")) {
                if (-not [string]::IsNullOrWhiteSpace($d) -and (Test-Path $d)) {
                    $candidatePaths.Add($d.Trim())
                }
            }
        }
        $dockerDirs2 = docker ps -a --filter "label=com.docker.compose.project=gickers-sh" --format '{{ index .Labels "com.docker.compose.project.working_dir" }}' 2>$null
        if ($dockerDirs2) {
            foreach ($d in ($dockerDirs2 -split "`r?`n")) {
                if (-not [string]::IsNullOrWhiteSpace($d) -and (Test-Path $d)) {
                    $candidatePaths.Add($d.Trim())
                }
            }
        }
    } catch {}
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
        $m = (Get-Content $envDocker | Where-Object { $_ -match '^APP_DOMAIN=(.+)' } | ForEach-Object { $matches[1].Trim(' "') })
        if ($m) { $instDom = $m }
    } elseif (Test-Path $envLocal) {
        $m = (Get-Content $envLocal | Where-Object { $_ -match '^APP_DOMAIN=(.+)' } | ForEach-Object { $matches[1].Trim(' "') })
        if ($m) { $instDom = $m }
    }
    if ([string]::IsNullOrEmpty($instDom)) { $instDom = "domaine non spécifié" }
    $detectedDomains.Add($instDom)

    $instState = "Arrêté"
    if ($dockerInstalled -eq 1) {
        try {
            $cList = docker ps -a --filter "label=com.docker.compose.project.working_dir=$dPath" -q 2>$null
            if ($cList) {
                $totalCnt = ($cList -split "`r?`n" | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }).Count
                $rList = docker ps --filter "label=com.docker.compose.project.working_dir=$dPath" -q 2>$null
                $runningCnt = if ($rList) { ($rList -split "`r?`n" | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }).Count } else { 0 }
                if ($totalCnt -gt 0) {
                    if ($runningCnt -gt 0) {
                        $instState = "Docker actif (${runningCnt}/${totalCnt} UP)"
                    } else {
                        $instState = "Docker arrêté (0/${totalCnt})"
                    }
                }
            }
        } catch {}
    }

    $instSsh = "22"
    if (Test-Path $envDocker) {
        $s = (Get-Content $envDocker | Where-Object { $_ -match '^SSH_PORT=(.+)' } | ForEach-Object { $matches[1].Trim(' "') })
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

$deployLog = Join-Path $env:TEMP "gickers_deploy.log"

if (Test-Path ".git") {
    Run-LoggedStep -StepLabel "Mise à jour du projet gickers.sh vers la dernière version" -LogFile $deployLog -WorkDir $installDir -CommandBlock {
        git fetch --depth 1 origin main 2>$null
        git reset --hard origin/main 2>$null
    }
} elseif (-not (Test-Path "compose.yaml")) {
    Run-LoggedStep -StepLabel "Téléchargement du projet gickers.sh" -LogFile $deployLog -WorkDir $installDir -CommandBlock {
        git clone --depth 1 https://github.com/gickerssh/gickers.sh.git .
    }
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
