# 125. Voice launcher starts the engine once, never twice (Small)

## Objective
`bin/cas-voice-start.cjs` refuses to start a second engine. One start gives one
engine, one listener on 127.0.0.1:8766 and "status: ready" in
`data/cas-voice.log`; a second start while one is alive or loading exits with a
line in the log and spawns nothing.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 125
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\124-voice-engine-status\result.md`
- `bin/cas-voice-start.cjs`, `bin/autostart.cjs` (its startVoice), `public/api/voice.php`

## Context
Checked 2026-11-01 (02:40 UTC) in `data/cas-voice.log`: two
"cas-voice start on V:" lines 0.38 s apart (01:40:37.552Z and .932Z). The
launcher writes that line once per run, so it was run twice; the engine does not
start itself twice. Both engines loaded side by side (every log line twice),
then one died binding 8766: "OSError: [Errno 10048] error while attempting to
bind on address ('127.0.0.1', 8766)". `data/cas-voice.pid` now holds the second
start's pid (11088, dead), so `voice.php` reports down while the survivor
(venv redirector pythonw 85576 and its base interpreter pythonw 48684) was still
loading with no listener. Nothing in the launcher checks for a running engine
before it spawns.

Wanted in `bin/cas-voice-start.cjs`, before any spawn:
- If 127.0.0.1:8766 accepts a connection, log "already running" and exit 0.
- If `data/cas-voice.pid` names a live process started less than 5 minutes ago,
  log "already starting (pid N)" and exit 0. (Models take about 2.5 minutes.)
- Take an exclusive lock file (`data/cas-voice.lock`, `wx` open) for the
  check-and-spawn so two runs 0.4 s apart cannot both pass; a stale lock older
  than 60 s is replaced.
- The pid file is written only by the run that actually spawned.
`autostart.cjs` keeps calling the launcher; the guard makes its watchdog safe too.

## Files you may touch
- `bin/cas-voice-start.cjs`
- `tests/AutostartTest.php`, `tests/VoiceApiTest.php`, `tests/CockpitVoiceTest.php`
- `.claude/work/125-voice-single-start/progress.md`, `result.md`

## Done-test
From plan.md card 125, word for word:

`c:\xampp\php\php.exe tests/run.php --filter Autostart` passes, including new
tests proving: the launcher exits without spawning when 8766 is listening;
exits without spawning when the pid file names a live process younger than 5
minutes; two launcher runs started together spawn exactly once (lock file);
only the spawning run writes the pid file; every spawn still passes
`windowsHide: true` and uses pythonw.

Then the mutation check through the runner
(`node C:\Users\roban\.claude\bin\project-test.cjs`): remove the lock, run
filtered, name the failing tests, restore, green.

Live check, recorded in result.md. Only when nothing listens on 8766 and no
pythonw engine is alive; if one is, stop and say so in result.md rather than
killing it. Run `node bin/cas-voice-start.cjs` twice in the same second; the log
shows one "start on" and one "already starting"; within 4 minutes exactly one
LISTENING line for 8766 in `netstat -ano`, "status: ready" in
`data/cas-voice.log`, and `/api/voice.php` says up. Leave that engine running.
No Chrome.

## Boundaries
No commit, no push, no install. Never edit anything under `D:\Dev\cas-voice`.
Never kill an engine process. `project.md`'s Never list applies.

## Progress
Keep `progress.md` beside this file from your first step, one line per step
that changes a file or finishes a unit of work: done, files, next, anything
half-written. Already there? You are resuming: read it and check the disk
against it first.

## Report
`result.md` beside this file, under 200 words: files, tests, mutation result
naming the failing tests, the live check lines, what was not done.
