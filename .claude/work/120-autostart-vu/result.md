# 120 result

Files: new `bin/autostart.cjs` (lock, waits for 8787, starts cas-voice, opens Chrome on monitor 2 once using a pid marker, 60 s watchdog capped at 3 restarts an hour, logs to `data/autostart.log`), `bin/autostart.vbs`, `bin/install-autostart.cjs`, `ops/install-apache-autostart.ps1` (round 2). `voice.js` has the 12-bar VU meter on an AnalyserNode and shows "No mic input" after 5 s; under reduced motion it shows a percent. Changed `cockpit.css`. Sort changes in `CockpitDay` (agenda, mail, follow-ups), `CockpitCare` (incidents), `CockpitDaily` (news by hour). In `views.js` the session talk list is no longer reversed and past agenda items are greyed.

Tests: new `AutostartTest` (5), one new test in `CockpitVoiceTest`, new `tests/CockpitOrderTest.php` (6). That last file is not on the brief's list, but the done-test asks for it. `--filter Cockpit` 74 green, `--filter Autostart` 5 green.

Mutation: with the lock dropped, "autostart exits when another copy holds the lock" failed. With the cap dropped, "the watchdog caps cas-voice restarts at 3 an hour" failed. Restored, green.

Calls: follow-ups have no due date, so they show newest first, not overdue-first. Existing CockpitDay tests expect that order.

Not done: "Working now" is still not sorted by activity, because projects carry no timestamp. No live frames captured. PHP was edited with a python script, not Edit.

Rob runs, as admin: `powershell -NoProfile -ExecutionPolicy Bypass -File ops\install-apache-service.ps1`, then `node bin\install-autostart.cjs`.

Round 2 (security audit): the LocalSystem service is gone. `ops/install-apache-service.ps1` is replaced by `ops/install-apache-autostart.ps1`: registers `\KC Dashboard\Apache` running httpd.exe at Rob's logon, S4U as Rob (no window, no stored password), RunLevel Limited, restart every minute on failure, no time limit. Keeps the ini backup and Apache=0, removes an existing Apache2.4 service only if present and says so, skips starting if 8787 answers. Not run. AutostartTest asserts backup before write, no `-k install`, no RunLevel Highest. Mutation: RunLevel Highest made the install test fail; restored, 5 green. Call: S4U over Interactive because httpd.exe is a console app and Interactive would open a window; S4U processes have no network credentials for Windows shares, if any spawned job needs them that is a finding.
