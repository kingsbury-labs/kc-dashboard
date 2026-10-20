# Registers the dashboard's background jobs in Task Scheduler under \KC Dashboard\.
# Usage: powershell -NoProfile -ExecutionPolicy Bypass -File bin\register-tasks.ps1 [-WhatIf]
# -WhatIf builds every task and prints it, and never calls Register-ScheduledTask.
# Runs S4U as the current user (no stored password, no elevation), one instance at a time.
# No mail task and nothing at logon. Never a name starting "KC Finance": those tasks belong to kc-finance.

param([switch]$WhatIf)

$ErrorActionPreference = 'Stop'

$TaskPath = '\KC Dashboard\'
$Php = 'c:\xampp\php\php.exe'
$Repo = Split-Path -Parent $PSScriptRoot

# Every bin/*.php job. Minutes repeats all day; At runs once a day at that time. Scripts run in order.
$Tasks = @(
    @{ Name = 'Worker';           Scripts = @('worker.php');                            Minutes = 1 }
    @{ Name = 'Fetch news';       Scripts = @('fetch-news.php');                        Minutes = 15 }
    @{ Name = 'Collect facts';    Scripts = @('collect-facts.php');                     Minutes = 30 }
    @{ Name = 'Collect projects'; Scripts = @('collect-projects.php', 'write-vault.php'); Minutes = 5 }
    @{ Name = 'Collect KC';       Scripts = @('collect-kc.php');                        At = '05:30' }
    @{ Name = 'Daily read';       Scripts = @('daily-read.php');                        At = '06:00' }
    @{ Name = 'Brief';            Scripts = @('brief.php');                             At = '06:15' }
)

$User = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$Principal = New-ScheduledTaskPrincipal -UserId $User -LogonType S4U -RunLevel Limited
$Settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable

foreach ($t in $Tasks) {
    $actions = @(foreach ($s in $t.Scripts) {
        $script = Join-Path $Repo ('bin\' + $s)
        New-ScheduledTaskAction -Execute $Php -Argument ('"' + $script + '"') -WorkingDirectory $Repo
    })
    if ($t.ContainsKey('Minutes')) {
        $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes $t.Minutes)
        $when = 'repeat ' + $trigger.Repetition.Interval
    } else {
        $trigger = New-ScheduledTaskTrigger -Daily -At $t.At
        # StartBoundary is stored in UTC; printed back in local time.
        $when = 'daily ' + ([datetime]::Parse($trigger.StartBoundary).ToString('HH:mm'))
    }

    if ($WhatIf) {
        $fields = @('WHATIF', ($TaskPath + $t.Name), $when, [string]$Principal.LogonType,
            [string]$Settings.MultipleInstances, [string]$Principal.RunLevel)
        foreach ($a in $actions) {
            $fields += ($a.Execute + ' ' + $a.Arguments + ' @ ' + $a.WorkingDirectory)
        }
        Write-Output ($fields -join ' | ')
        continue
    }

    Register-ScheduledTask -TaskName $t.Name -TaskPath $TaskPath -Action $actions -Trigger $trigger `
        -Principal $Principal -Settings $Settings -Force -ErrorAction Stop | Out-Null
    Write-Output ('registered ' + $TaskPath + $t.Name)
}
