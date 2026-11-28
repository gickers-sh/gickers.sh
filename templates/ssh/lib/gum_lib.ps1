$esc = [char]27
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

function Invoke-QuietNative {
    param([scriptblock]$CommandBlock)
    $prevEap = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    $global:LASTEXITCODE = 0
    try {
        return (& $CommandBlock 2>$null)
    } catch {
        $global:LASTEXITCODE = 1
        return $null
    } finally {
        $ErrorActionPreference = $prevEap
    }
}

function Write-Utf8NoBomFile {
    param(
        [string]$Path,
        [string]$Content
    )
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $Content, $utf8NoBom)
}

function Run-LoggedStep {
    param(
        [string]$StepLabel,
        [string]$LogFile,
        [string]$WorkDir,
        [scriptblock]$CommandBlock
    )

    $chars = @('⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏')
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    $logDir = Split-Path -Parent $LogFile
    if (-not [string]::IsNullOrEmpty($logDir) -and -not (Test-Path $logDir)) {
        New-Item -ItemType Directory -Force -Path $logDir | Out-Null
    }

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
            $prevEap = $ErrorActionPreference
            $ErrorActionPreference = "Continue"
            $global:LASTEXITCODE = 0
            try {
                & $CommandBlock 2>&1 | ForEach-Object { "$_" } | Tee-Object -FilePath $LogFile
                $exitCode = if ($null -ne $LASTEXITCODE) { [int]$LASTEXITCODE } else { 0 }
            } catch {
                $exitCode = 1
                $_ | Out-String | Out-File -FilePath $LogFile -Append -Encoding UTF8
            } finally {
                $ErrorActionPreference = $prevEap
            }
        } else {
            Write-Host -NoNewline "$esc[?25l"
            $job = Start-Job -ScriptBlock {
                param($cmdText, $dir, $log)
                $ErrorActionPreference = "Continue"
                if (-not [string]::IsNullOrEmpty($dir) -and (Test-Path $dir)) {
                    Set-Location $dir
                }
                $global:LASTEXITCODE = 0
                try {
                    $sb = [scriptblock]::Create($cmdText)
                    & $sb 2>&1 | ForEach-Object { "$_" } | Out-File -FilePath $log -Encoding UTF8
                    if ($null -ne $LASTEXITCODE -and $LASTEXITCODE -ne 0) {
                        return [int]$LASTEXITCODE
                    }
                    return 0
                } catch {
                    $_ | Out-String | Out-File -FilePath $log -Append -Encoding UTF8
                    return 1
                }
            } -ArgumentList $cmdStr, (Get-Location).Path, $LogFile

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
            $jobResult = Receive-Job -Job $job
            $exitCode = 0
            if ($job.State -eq 'Failed') {
                $exitCode = 1
            } elseif ($null -ne $jobResult) {
                $lastVal = @($jobResult)[-1]
                if ($lastVal -is [int] -or $lastVal -is [long]) {
                    $exitCode = [int]$lastVal
                }
            }
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
        $retryStep = $false
        while ($true) {
            $errChoice = Gum-Choose -Title "Action en cas d'erreur sur '$StepLabel'" -Options $errOpts
            if ($errChoice -eq 0) {
                $retryStep = $true
                break
            } elseif ($errChoice -eq 1) {
                if (Test-Path $LogFile) {
                    Get-Content -Path $LogFile | Out-Host -Paging
                }
            } elseif ($errChoice -eq 2) {
                Write-Host "  $escGold!$escReset $escBold${escWhite}Étape ignorée par l'administrateur.$escReset`n"
                return 0
            } else {
                Write-Host "`n  $escPink✖$escReset $escBold${escPink}Installation interrompue par l'administrateur.$escReset`n"
                exit $exitCode
            }
        }
        if ($retryStep) { continue }
    }
}

function Manage-InstanceUpdate {
    param([string]$Dir)
    $logFile = Join-Path $env:TEMP "gickers_update.log"
    Write-Host "`n  $escBold${escCyan}🔄 Mise à jour de l'instance gickers.sh : $Dir$escReset`n"

    if (Test-Path (Join-Path $Dir ".git")) {
        Run-LoggedStep -StepLabel "Récupération du dernier code source (git pull)" -LogFile $logFile -WorkDir $Dir -CommandBlock {
            git fetch --depth 1 origin main
            if ($LASTEXITCODE -ne 0) { throw "git fetch failed ($LASTEXITCODE)" }
            git reset --hard origin/main
            if ($LASTEXITCODE -ne 0) { throw "git reset failed ($LASTEXITCODE)" }
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

    $curDomain = (Get-Content $envFile | Where-Object { $_ -match '^APP_DOMAIN=(.+)' } | ForEach-Object { $matches[1].Trim(' "') } | Select-Object -First 1)
    if ([string]::IsNullOrEmpty($curDomain)) { $curDomain = "gits.run" }
    $curSsh = (Get-Content $envFile | Where-Object { $_ -match '^SSH_PORT=(.+)' } | ForEach-Object { $matches[1].Trim(' "') } | Select-Object -First 1)
    if ([string]::IsNullOrEmpty($curSsh)) { $curSsh = "22" }
    $curHttp = (Get-Content $envFile | Where-Object { $_ -match '^HTTP_PORT=(.+)' } | ForEach-Object { $matches[1].Trim(' "') } | Select-Object -First 1)
    if ([string]::IsNullOrEmpty($curHttp)) { $curHttp = "80" }
    $curHttps = (Get-Content $envFile | Where-Object { $_ -match '^HTTPS_PORT=(.+)' } | ForEach-Object { $matches[1].Trim(' "') } | Select-Object -First 1)
    if ([string]::IsNullOrEmpty($curHttps)) { $curHttps = "443" }

    $newDomain = Gum-Input -PromptLabel "Domaine public (FQDN)" -DefaultValue $curDomain
    $newSsh = Gum-Input -PromptLabel "Port SSH public" -DefaultValue $curSsh
    $newHttp = Gum-Input -PromptLabel "Port HTTP public" -DefaultValue $curHttp
    $newHttps = Gum-Input -PromptLabel "Port HTTPS public" -DefaultValue $curHttps

    $signOpts = @("Inscriptions ouvertes (true)", "Inscriptions fermées (false)")
    $signChoice = Gum-Choose -Title "Politique d'inscriptions" -Options $signOpts
    $newSignup = if ($signChoice -eq 1) { "false" } else { "true" }
    $newSshUrl = if ($newSsh -eq "22" -or [string]::IsNullOrEmpty($newSsh)) { "ssh://$newDomain" } else { "ssh://${newDomain}:${newSsh}" }

    $lines = Get-Content $envFile
    $newLines = @()
    foreach ($line in $lines) {
        if ($line -match '^APP_DOMAIN=') { $newLines += "APP_DOMAIN=$newDomain" }
        elseif ($line -match '^SSH_PORT=') { $newLines += "SSH_PORT=$newSsh" }
        elseif ($line -match '^HTTP_PORT=') { $newLines += "HTTP_PORT=$newHttp" }
        elseif ($line -match '^HTTPS_PORT=') { $newLines += "HTTPS_PORT=$newHttps" }
        elseif ($line -match '^SOFT_SERVE_ALLOW_SIGNUP=') { $newLines += "SOFT_SERVE_ALLOW_SIGNUP=$newSignup" }
        elseif ($line -match '^SOFT_SERVE_SSH_PUBLIC_URL=') { $newLines += "SOFT_SERVE_SSH_PUBLIC_URL=$newSshUrl" }
        elseif ($line -match '^SOFT_SERVE_HTTP_PUBLIC_URL=') { $newLines += "SOFT_SERVE_HTTP_PUBLIC_URL=https://$newDomain" }
        else { $newLines += $line }
    }
    Write-Utf8NoBomFile -Path $envFile -Content (($newLines -join "`n") + "`n")

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
            docker compose --env-file .env.docker down -v --remove-orphans
            if ($LASTEXITCODE -ne 0) {
                docker compose down -v --remove-orphans
            }
            $global:LASTEXITCODE = 0
        }
    }

    Invoke-QuietNative { docker volume rm -f gickers-sh_gickers-data gickers-sh_gickers-db gickers-sh_gickers-var gickers-sh_gickers-deploy-engine } | Out-Null

    if (Test-Path $Dir) {
        $logFileFs = Join-Path $env:TEMP "gickers_delete_fs.log"
        $parentDir = Split-Path -Parent $Dir
        if ([string]::IsNullOrEmpty($parentDir) -or -not (Test-Path $parentDir)) { $parentDir = $env:TEMP }
        if ((Get-Location).Path -eq $Dir) { Set-Location $parentDir }
        $escapedDir = $Dir -replace "'", "''"
        $delCmd = [scriptblock]::Create("Remove-Item -Recurse -Force -LiteralPath '$escapedDir'")
        Run-LoggedStep -StepLabel "Suppression du répertoire $Dir" -LogFile $logFileFs -WorkDir $parentDir -CommandBlock $delCmd
    }

    Write-Host "  $escGreen✔$escReset $escBold${escWhite}Instance $Domain supprimée définitivement avec succès. Ports et ressources libérés.$escReset`n"
    exit 0
}

