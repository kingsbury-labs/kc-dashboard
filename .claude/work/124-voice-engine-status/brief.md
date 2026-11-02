# 124. Voice engine: say when it is down or starting, log it, never a window (Normal)

## Objective
The Cas bar tells the truth about the voice engine. It never shows "Listening"
before the engine's socket is open; it shows "Voice engine starting" while the
engine process is alive but still loading its models (about 2.5 minutes), and
"Voice engine not running" when there is no process. The launcher keeps a log,
and nothing in the start chain opens a visible window.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 124
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\120-autostart-vu\result.md`
- `bin/cas-voice-start.cjs`, `bin/autostart.cjs`, `public/cockpit/js/voice.js`, `public/cockpit/js/main.js:50-80`
- `D:\Dev\cas-voice\proto\run.cmd`, `D:\Dev\cas-voice\proto\server.py` (read only; never edit cas-voice, it is not a git repo)

## Context
Rob, 2026-11-01: "I said hey cas and it registered on the vu but I had no
response." Cause, checked: nothing listened on 127.0.0.1:8766 (cas-voice was
not running; its last log ended 15:15). The meter moves because it reads the
mic in the browser. Three things hid that:
- `voice.js` `toggleTalk()` (around line 234) opens the mic, calls `connect()`
  and sets "Listening" at once; the socket failure lands later. Wake mode
  retries every 5 s in `onclose` with no message beyond the off label.
- `bin/cas-voice-start.cjs` spawns with `stdio: 'ignore'`, so the PM's first
  start died with no trace. The PM's working start logged to
  `%TEMP%\casvoice-detached.log`; 8766 answered after 152 s of model loading.
- Rob saw python console windows pop tonight. The chain is
  `cas-voice-start.cjs` -> `cmd.exe /c run.cmd` (subst, then
  `venv\Scripts\python.exe server.py`). `brain.py:80` already passes
  CREATE_NO_WINDOW; `venv\Scripts\pythonw.exe` exists. `server.py:613` opens
  a browser only for an "open" item when its `--open` flag is set; leave that.

Wanted:
1. Mic and "Listening" wait for the socket's `onopen`. If it is not open
   within 3 s, ask the server for the engine state (below), show it in the Cas
   bar, close the mic.
2. `cas-voice-start.cjs` writes stdout and stderr to `data/cas-voice.log`
   (appended, a start line with the time), and writes `data/cas-voice.pid`
   (the engine's pid and start time).
3. No visible window anywhere in the chain. Every spawn keeps
   `windowsHide: true`; run the engine with `pythonw.exe` or a hidden console
   so neither cmd nor python can show one. Keep run.cmd's drive-letter
   mapping working (do it in the launcher if you stop going through run.cmd).
   `autostart.cjs`' restart path uses the same launcher.
4. `public/api/voice.php` (GET, token checked like the other APIs, read-only):
   `{"state":"up"}` when 8766 accepts a connection, `"starting"` when the pid
   in `data/cas-voice.pid` is alive and 8766 is not up, `"down"` otherwise.
   voice.js polls it every 10 s while the socket is closed and shows
   "Voice engine starting" or "Voice engine not running"; it reconnects when it
   turns up.

## Files you may touch
- `public/cockpit/js/voice.js`, `public/cockpit/js/main.js` (Cas bar label only)
- `bin/cas-voice-start.cjs`, `bin/autostart.cjs` (launcher call only)
- `public/api/voice.php` (new)
- `tests/CockpitVoiceTest.php`, `tests/AutostartTest.php`, `tests/VoiceApiTest.php` (new)
- `.claude/work/124-voice-engine-status/progress.md`, `result.md`, captures in that folder

Card 123 owns `cockpit.css` and `views.js`; do not touch them.

## Done-test
From plan.md card 124, word for word:

`c:\xampp\php\php.exe tests/run.php --filter CockpitVoice`, `--filter Autostart`
and `--filter VoiceApi` pass, including tests proving: voice.js sets
"Listening" only inside the socket's onopen path; after 3 s without onopen it
shows the engine state from `/api/voice.php`; `cas-voice-start.cjs` sends
output to `data/cas-voice.log`, never `stdio: 'ignore'`, writes
`data/cas-voice.pid`, and every spawn passes `windowsHide: true` with no
`python.exe` console launch; voice.php returns up, starting and down against
a fake listener and fake pid file, and refuses a request without the token.

Then the mutation check through the runner
(`node C:\Users\roban\.claude\bin\project-test.cjs`): put `stdio: 'ignore'`
back, run filtered, name the failing tests, restore, green.

Live check, recorded in result.md: with the engine stopped, `/api/voice.php`
says down; start it with the launcher, it says starting within 5 s and up when
8766 answers; `tasklist /v /fi "imagename eq python*"` and
`/fi "imagename eq cmd.exe"` show no engine process with a visible window
title; `data/cas-voice.log` has the start line. Leave the engine running at
the end. No Chrome; headless capture only if you need a picture.

## Boundaries
No commit, no push, no install. Never edit anything under `D:\Dev\cas-voice`.
Stopping and starting the cas-voice engine for the live check is allowed;
leave it up. `project.md`'s Never list applies.

## Progress
Keep `progress.md` beside this file from your first step, one line per step
that changes a file or finishes a unit of work: done, files, next, anything
half-written. Already there? You are resuming: read it and check the disk
against it first.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, mutation result naming the failing tests, the live check results, and
how the window problem was closed. Say what was not done.
