# 120. Cockpit comes back by itself after a restart; mic VU meter (Normal)

## Objective
After the PC restarts and Rob logs in, everything comes back with no hands:
the dashboard server, the cas-voice engine, and Chrome showing the cockpit full
screen on monitor 2, with a watchdog that restarts cas-voice if it dies. The
Cas bar shows a live mic level meter whenever the mic is open, and says "No mic
input" when it hears nothing.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 120
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\110-cockpit-voice\result.md`, `bin\cas-voice-start.cjs`, `public\cockpit\js\voice.js`
- `D:\Dev\htdocs\_kc-dashboard\bin\register-tasks.ps1` (style), `ops\apache-kc-dashboard.conf`
- `D:\Dev\cas-voice\proto\README.md` (run.cmd flags; do not edit cas-voice)

## Context
- Rob, 2026-11-01: "when the computer restarts, this whole dashboard and
  functionality needs to restart, including node commands - I won't be
  manually restarting anything, it needs to be automatic and seamless." and
  "Voice needs a VU meter so I can see if it's even being picked up by the mic."
- Checked 2026-11-01: Apache (httpd, serving 127.0.0.1:8787) is NOT a Windows
  service; it runs only because XAMPP Control Panel auto-starts it, and nothing
  starts the panel at logon. The background jobs are registered tasks
  (`\KC Dashboard\`, S4U). Ollama starts from the Startup folder. Monitor 2 is
  DISPLAY2, 1080x1920, at x=3440, y=-262.
- Apache as a service needs admin, so Rob does it once. Write
  `ops/install-apache-service.ps1` (for Rob to run as admin): install
  `C:\xampp\apache\bin\httpd.exe -k install -n Apache2.4`, set it to start
  automatically, set `Apache=0` under `[Autostart]` in
  `C:\xampp\xampp-control.ini` so the panel does not start a second copy (back
  the ini up first), start the service, and print the 8787 health check result.
  Never run it yourself.
- Logon launcher, no admin, no visible window: `bin/autostart.cjs` (Node, every
  spawn `windowsHide: true`, detached children) started at logon by a Startup
  folder shortcut to `wscript.exe //B bin\autostart.vbs` (the .vbs runs node
  hidden). It: waits up to 3 minutes for http://127.0.0.1:8787/ to answer; starts
  cas-voice through the existing launcher if port 8766 is not listening; opens
  Chrome once with `--new-window --app=http://127.0.0.1:8787/cockpit.php
  --window-position=3440,-262 --window-size=1080,1920 --start-fullscreen`
  (skip if a cockpit window is already open: check by a marker file with the
  Chrome pid); then stays as a watchdog checking every 60 s and restarting
  cas-voice if 8766 stops answering, at most 3 restarts an hour, logging to
  `data/autostart.log`. A second copy exits at once (lock file with pid).
- `bin/install-autostart.cjs`: creates the Startup shortcut (via the WScript
  Shell COM object in a hidden cscript, or a .lnk writer) pointing at the .vbs;
  idempotent; `--remove` deletes it. Rob runs it, or the coordinator does.
- VU meter: in `voice.js`, an AnalyserNode on the same mic stream feeds a
  12-bar level meter in the Cas bar (about 30 updates a second, requestAnimationFrame),
  visible whenever the mic is open in wake or talk mode; if the peak stays
  under a small floor for 5 s, the bar shows "No mic input". Still under
  prefers-reduced-motion: show a single numeric percent instead of moving bars.

- List order, every list on the page (Rob, 2026-11-01: "for ALL items, I'm
  working top down - pagination/inline scrolling is ok, but ensure output order
  is newest/top working down"): Needs you, Agent talk, Email summaries,
  Working now (most recent activity first), Care alerts, AI news (newest
  first among the filtered stories; Scorer only breaks ties within the same
  hour), the session transcript and files lists: newest at the top. Time-bound
  lists: today's agenda shows the next upcoming event first (past events
  greyed below), follow-ups put overdue then soonest due at the top. Every
  "Show more" continues downward in the same order. Fix any list that does not
  follow this, in the server sort or the view, whichever owns the order.

## Files you may touch
- `src/Cockpit/` (sort order only), `src/News/` (sort order only)
- `bin/autostart.cjs`, `bin/autostart.vbs`, `bin/install-autostart.cjs` (new)
- `ops/install-apache-service.ps1` (new)
- `public/cockpit/js/voice.js`, `public/cockpit/js/views.js`, `public/cockpit/cockpit.css`
- `tests/AutostartTest.php` (new), `tests/CockpitVoiceTest.php`
- `.claude/work/120-autostart-vu/progress.md`, `result.md`

## Done-test
From plan.md card 120, word for word:

`c:\xampp\php\php.exe tests/run.php --filter Autostart` and `--filter
CockpitVoice` pass: every spawn in `bin/autostart.cjs` and
`bin/install-autostart.cjs` passes `windowsHide: true`; autostart exits when
another copy holds the lock; it opens Chrome with the monitor 2 position and
the cockpit URL in app mode and does not open a second window when the marker
is live; the watchdog caps restarts at 3 an hour; voice.js builds an
AnalyserNode on the mic stream and shows "No mic input" after 5 s under the
floor; `ops/install-apache-service.ps1` backs up xampp-control.ini before
changing it. A new CockpitOrder test proves, against fixtures, each list in
the snapshot comes newest first (agenda: next upcoming first; follow-ups:
overdue then soonest due first).

Then the mutation check (drop the lock, then drop the restart cap), name the
failing tests, restore, green. Filtered runs only.

## Boundaries
No commit, no push. Do not run the Apache script or install the shortcut;
Rob or the coordinator does that after review. Do not edit cas-voice. Never
list applies.

## Progress
Keep `progress.md` beside this file, one line per step. Resume from it if present.

## Report
`result.md` under 200 words: files, tests, mutation result, the two commands
Rob or the coordinator runs. Say what was not done.
