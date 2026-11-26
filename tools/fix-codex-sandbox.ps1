<#
  Repair: Codex / ChatGPT Windows sandbox setup fails
  Symptom (app UI):   "Windows 设置未完成 / 设置已停止"  -> 重新设置

  Confirmed root cause (from %USERPROFILE%\.codex\.sandbox\setup_error.json):
      code    = helper_sandbox_lock_failed
      message = lock sandbox bin dir \\?\C:\Users\Administrator\.codex\.sandbox-bin
                failed: open directory ... NtCreateFile: Access is denied. (os error 5)

  The setup helper cannot OPEN the directory <profile>\.codex\.sandbox-bin.
  Typical causes (this script handles all of them):
    - a leftover DENY ACE on the folder from an interrupted sandbox run
    - a leaked handle from a stale codex.exe / codex-command-runner*.exe
    - a broken ACL / ownership after an upgrade
    - antivirus (Defender CFA / third-party AV) blocking that path

  What it does (safe, reversible):
    1. backs up the current ACL of .sandbox-bin
    2. stops Codex / ChatGPT processes (releases any handle on the folder)
    3. reports DENY ACEs and current owner
    4. takeown + icacls /reset on .sandbox-bin
    5. if still unreadable, deletes .sandbox-bin (it only holds regenerateable
       helper exes: codex-command-runner-*.exe -- the app recreates them)
    6. clears stale setup_error.json / setup_marker.json so setup runs again
    7. prints Defender Controlled Folder Access / AV status

  Usage (PowerShell started as Administrator):
    powershell -NoProfile -ExecutionPolicy Bypass -File D:\main\tools\fix-codex-sandbox.ps1

  Then open the ChatGPT/Codex app and press "重新设置" again.

  Rollback:
    icacls "<profile>\.codex\.sandbox-bin" /restore <backup dir>\sandbox-bin.acl
    (backup dir is printed at the end)
#>

param(
    [switch]$AddDefenderExclusion
)

$ErrorActionPreference = 'Continue'
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch {}

# ---------------------------------------------------------------- elevation
$ident = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($ident)
$isAdmin = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    Write-Host 'This script must run elevated.' -ForegroundColor Red
    Write-Host 'Start PowerShell with "Run as administrator", then re-run it.' -ForegroundColor Red
    exit 1
}

$root  = Join-Path $env:USERPROFILE '.codex'
$bin   = Join-Path $root '.sandbox-bin'
$state = Join-Path $root '.sandbox'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$bkp   = Join-Path $PSScriptRoot ("backup\codex-sandbox-acl-" + $stamp)

Write-Host ''
Write-Host '===== 0. paths =====' -ForegroundColor Cyan
Write-Host ("profile   : " + $env:USERPROFILE)
Write-Host ("bin dir   : " + $bin)
Write-Host ("bin exists: " + (Test-Path -LiteralPath $bin))

# ------------------------------------------------------------- 1. backup ACL
Write-Host ''
Write-Host '===== 1. backup ACL =====' -ForegroundColor Cyan
try {
    New-Item -ItemType Directory -Force -Path $bkp | Out-Null
    if (Test-Path -LiteralPath $bin) {
        & icacls.exe $bin /save (Join-Path $bkp 'sandbox-bin.acl') /t /c | Out-Null
        Write-Host ("ACL saved to " + $bkp)
    } else {
        Write-Host 'bin dir does not exist, nothing to back up.'
    }
} catch {
    Write-Host ("backup failed: " + $_.Exception.Message) -ForegroundColor Yellow
}

# ------------------------------------------- 2. stop processes holding a lock
Write-Host ''
Write-Host '===== 2. stop Codex / ChatGPT processes =====' -ForegroundColor Cyan
$names = @('codex', 'chatgpt', 'codex-command-runner')
$procs = @()
foreach ($n in $names) {
    $found = @(Get-Process -Name $n -ErrorAction SilentlyContinue)
    if ($found.Count -gt 0) { $procs += $found }
}
$procs = @($procs | Sort-Object Id -Unique)

if ($procs.Count -eq 0) {
    Write-Host 'no running Codex/ChatGPT process found.'
} else {
    foreach ($p in $procs) {
        Write-Host ("stopping " + $p.ProcessName + " (pid " + $p.Id + ")")
        try { Stop-Process -Id $p.Id -Force -ErrorAction Stop } catch {
            Write-Host ("  could not stop: " + $_.Exception.Message) -ForegroundColor Yellow
        }
    }
    Start-Sleep -Seconds 2
}

# ------------------------------------------------- 3. report DENY ACE + owner
Write-Host ''
Write-Host '===== 3. current ACL of .sandbox-bin =====' -ForegroundColor Cyan
if (Test-Path -LiteralPath $bin) {
    try {
        $acl = Get-Acl -LiteralPath $bin
        Write-Host ("owner: " + $acl.Owner)
        $deny = @($acl.Access | Where-Object { $_.AccessControlType -eq 'Deny' })
        if ($deny.Count -gt 0) {
            Write-Host ("FOUND " + $deny.Count + " DENY entr(y/ies) -- this is the usual culprit:") -ForegroundColor Yellow
            foreach ($d in $deny) {
                Write-Host ("  DENY " + $d.IdentityReference + "  " + $d.FileSystemRights)
            }
        } else {
            Write-Host 'no explicit DENY entry.'
        }
        foreach ($a in $acl.Access) {
            Write-Host ("  " + $a.AccessControlType + " " + $a.IdentityReference + " " + $a.FileSystemRights + " inherit=" + $a.IsInherited)
        }
    } catch {
        Write-Host ("Get-Acl failed: " + $_.Exception.Message) -ForegroundColor Yellow
    }

    Write-Host ''
    Write-Host 'can the directory be opened right now?'
    $canOpen = $true
    try {
        $null = [System.IO.Directory]::GetFileSystemEntries($bin)
        Write-Host '  YES - access ok' -ForegroundColor Green
    } catch {
        $canOpen = $false
        Write-Host ("  NO  - " + $_.Exception.Message) -ForegroundColor Yellow
    }
} else {
    $canOpen = $true
}

# --------------------------------------------- 4. takeown + reset permissions
Write-Host ''
Write-Host '===== 4. reset ownership + ACL =====' -ForegroundColor Cyan
if (Test-Path -LiteralPath $bin) {
    & takeown.exe /f $bin /r /d Y 2>&1 | ForEach-Object { Write-Host ("  " + $_) }
    & icacls.exe $bin /reset /t /c /q 2>&1 | ForEach-Object { Write-Host ("  " + $_) }

    $canOpen = $true
    try {
        $null = [System.IO.Directory]::GetFileSystemEntries($bin)
        Write-Host '  access restored by ACL reset.' -ForegroundColor Green
    } catch {
        $canOpen = $false
        Write-Host ("  still denied after reset: " + $_.Exception.Message) -ForegroundColor Yellow
    }
}

# ------------------------------- 5. last resort: delete the regenerable folder
Write-Host ''
Write-Host '===== 5. remove .sandbox-bin if still unusable =====' -ForegroundColor Cyan
if (Test-Path -LiteralPath $bin) {
    if (-not $canOpen) {
        Write-Host 'deleting .sandbox-bin (helper exes are re-created by the app on next setup)' -ForegroundColor Yellow
        & cmd.exe /c ('rd /s /q "' + $bin + '"') 2>&1 | ForEach-Object { Write-Host ("  " + $_) }
        Start-Sleep -Seconds 1
    } else {
        Write-Host 'folder is accessible, deleting it anyway to force a clean rebuild' -ForegroundColor Yellow
        & cmd.exe /c ('rd /s /q "' + $bin + '"') 2>&1 | ForEach-Object { Write-Host ("  " + $_) }
        Start-Sleep -Seconds 1
    }
}
if (Test-Path -LiteralPath $bin) {
    Write-Host 'WARNING: .sandbox-bin still exists -- a handle is likely held by another process.' -ForegroundColor Red
    Write-Host 'Close the ChatGPT/Codex app completely (also check Task Manager / tray), then re-run.' -ForegroundColor Red
} else {
    Write-Host 'bin dir removed (or absent).' -ForegroundColor Green
}

# ------------------------------------ 6. clear stale setup state so setup reruns
Write-Host ''
Write-Host '===== 6. clear stale setup state =====' -ForegroundColor Cyan
foreach ($f in @('setup_error.json', 'setup_marker.json')) {
    $p = Join-Path $state $f
    if (Test-Path -LiteralPath $p) {
        Remove-Item -LiteralPath $p -Force -ErrorAction SilentlyContinue
        Write-Host ("removed " + $p)
    } else {
        Write-Host ("not present " + $p)
    }
}

# ------------------------------------------------------- 7. antivirus status
Write-Host ''
Write-Host '===== 7. antivirus / controlled folder access =====' -ForegroundColor Cyan
try {
    $mp = Get-MpPreference -ErrorAction Stop
    Write-Host ("Defender ControlledFolderAccess: " + $mp.EnableControlledFolderAccess + "  (0=off 1=on 2=audit)")
    $ex = @($mp.ExclusionPath | Where-Object { $_ })
    if ($ex.Count -gt 0) {
        Write-Host ("existing ExclusionPath: " + ($ex -join ' ; '))
    } else {
        Write-Host 'existing ExclusionPath: (none)'
    }
    if ($AddDefenderExclusion) {
        Add-MpPreference -ExclusionPath $root -ErrorAction SilentlyContinue
        Write-Host ("added Defender exclusion for " + $root) -ForegroundColor Green
    }
} catch {
    Write-Host ("Defender query failed: " + $_.Exception.Message) -ForegroundColor Yellow
}
try {
    $av = @(Get-CimInstance -Namespace 'root/SecurityCenter2' -ClassName AntiVirusProduct -ErrorAction Stop)
    foreach ($a in $av) { Write-Host ("AV product: " + $a.displayName) }
} catch {
    Write-Host 'could not enumerate AV products.'
}

# ------------------------------------------------------------------ summary
Write-Host ''
Write-Host '===== done =====' -ForegroundColor Cyan
Write-Host 'Next step:'
Write-Host '  1. open the ChatGPT / Codex app'
Write-Host '  2. press "重新设置" (Reset setup) and let it finish'
Write-Host '  3. if it fails again, send these two files back for analysis:'
Write-Host ("     " + (Join-Path $state 'setup_error.json'))
Write-Host ("     " + (Join-Path $state ('sandbox.' + (Get-Date -Format 'yyyy-MM-dd') + '.log')))
Write-Host ''
Write-Host ("ACL backup (rollback): " + $bkp)
Write-Host ''
