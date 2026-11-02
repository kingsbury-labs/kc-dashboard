# Card 120: starts XAMPP's Apache (the dashboard on 127.0.0.1:8787) at Rob's logon, as Rob, from Task Scheduler.
# Never a Windows service: a LocalSystem Apache would run every .php under D:\Dev\htdocs as SYSTEM.
# For Rob, once, from an admin PowerShell (admin only to register the task and drop an old service):
#   powershell -NoProfile -ExecutionPolicy Bypass -File ops\install-apache-autostart.ps1
# Backs up xampp-control.ini, then sets Apache=0 under [Autostart] so the Control Panel never starts a second copy.

$ErrorActionPreference = 'Stop'

$TaskPath = '\KC Dashboard\'
$TaskName = 'Apache'
$Httpd = 'C:\xampp\apache\bin\httpd.exe'
$OldService = 'Apache2.4'
$Ini = 'C:\xampp\xampp-control.ini'

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { throw 'Run this from an administrator PowerShell.' }

# Round one installed a LocalSystem service; remove it if it is there.
if (Get-Service $OldService -ErrorAction SilentlyContinue) {
    Stop-Service $OldService -Force -ErrorAction SilentlyContinue
    & sc.exe delete $OldService | Out-Null
    Write-Host "Removed the old $OldService service (it ran as LocalSystem)."
}

if (Test-Path $Ini) {
    $backup = "$Ini.bak-" + (Get-Date -Format 'yyyyMMdd-HHmmss')
    Copy-Item $Ini $backup
    Write-Host "Backed up $Ini to $backup"
    $lines = [Collections.Generic.List[string]](Get-Content $Ini)
    $start = $lines.IndexOf('[Autostart]')
    if ($start -lt 0) {
        $lines.Add('[Autostart]'); $lines.Add('Apache=0')
    } else {
        $end = $start + 1
        while ($end -lt $lines.Count -and -not $lines[$end].StartsWith('[')) { $end++ }
        $found = $false
        for ($i = $start + 1; $i -lt $end; $i++) {
            if ($lines[$i] -match '^Apache=') { $lines[$i] = 'Apache=0'; $found = $true }
        }
        if (-not $found) { $lines.Insert($start + 1, 'Apache=0') }
    }
    Set-Content $Ini $lines
}

# S4U as Rob, Limited: no window, no stored password, no elevation. Restarts on failure, no time limit.
$User = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$Principal = New-ScheduledTaskPrincipal -UserId $User -LogonType S4U -RunLevel Limited
$Settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -Hidden `
    -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero)
$Trigger = New-ScheduledTaskTrigger -AtLogOn -User $User
$Action = New-ScheduledTaskAction -Execute $Httpd -WorkingDirectory 'C:\xampp\apache\bin'

Register-ScheduledTask -TaskName $TaskName -TaskPath $TaskPath -Action $Action -Trigger $Trigger `
    -Principal $Principal -Settings $Settings -Force -ErrorAction Stop | Out-Null
Write-Host ('registered ' + $TaskPath + $TaskName)

function Test-Dashboard {
    try {
        $r = Invoke-WebRequest 'http://127.0.0.1:8787/' -UseBasicParsing -TimeoutSec 5
        return $r.StatusCode
    } catch { return $null }
}

$code = Test-Dashboard
if ($code) {
    Write-Host "8787 already answers (HTTP $code); not starting a second Apache. The task takes over at next logon."
    exit 0
}

Start-ScheduledTask -TaskPath $TaskPath -TaskName $TaskName
Start-Sleep -Seconds 3
$code = Test-Dashboard
if ($code) { Write-Host "8787 health check: HTTP $code" } else { Write-Host '8787 health check failed'; exit 1 }
