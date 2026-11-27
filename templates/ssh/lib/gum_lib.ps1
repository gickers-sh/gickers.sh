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

