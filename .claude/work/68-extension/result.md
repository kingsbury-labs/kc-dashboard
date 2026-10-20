# 68 result

Files: new `chrome-extension/` (manifest.json, newtab.html, newtab.js), `bin/register-tasks.ps1`, tests ExtensionTest (4) and TasksScriptTest (4). No index.php or View.php change needed.

Both filters pass. Full suite: 395 passed, 0 failed.

Script: 7 tasks under `\KC Dashboard\`, S4U, RunLevel Limited, IgnoreNew, StartWhenAvailable. collect-kc daily at 05:30 (card gave no time). collect-projects and write-vault are one task with two actions, in order. `-WhatIf` builds the real objects and prints them, never reaching Register-ScheduledTask. The test runs it through ProcessRunner inside a wrapper that shadows every scheduler cmdlet and exits 3 unless the shadow is what resolves.

Mutations, each restored: manifest `permissions` failed "asks for no permissions"; newtab.js URL to localhost failed "navigates to exactly"; fetch-news 15 to 10 min failed "every bin/*.php job".

Incident, Rob should check: my first -WhatIf-guard mutation ran before the wrapper imported the module first, so ScheduledTasks' own function beat the shadow and the real Register-ScheduledTask was called 7 times. It most likely failed with access denied (`C:\Windows\System32\Tasks\KC Dashboard` does not exist), but the classifier refused my Get-ScheduledTask check. Run `Get-ScheduledTask -TaskPath '\KC Dashboard\'`. Fixed since: wrapper verifies the shadow; script now has `-ErrorAction Stop`.

Owed: the -WhatIf-guard mutation rerun was refused ("Unauthorized Persistence"), restored at once.
