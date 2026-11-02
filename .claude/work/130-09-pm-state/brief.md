# 130-09. Live PM state (Normal)

## Objective
`php bin/pm-state.php --out <file>` writes one JSON file the voice PM reads every 12 minutes: open asks, the conch holder, every project's loop, the queue, peer sessions, the go pending, and findings that match the HQ check. Plan 130 card 9.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- Plan: `D:\Dev\htdocs\_kc-dashboard\.claude\work\130-voice-pm\plan.md`, the opening section and card 9 (the spec; follow it word for word), and card 11 (the conch refusal log this card reads once it exists).
- `src\Projects\LoopState.php` (`read($project)` :23, `checklist($file)` :79), `src\Projects\ProjectList.php` (`fromConfig`, `names`, `resolve`), `src\Running\SessionRegistry.php` (`read()` :43)
- One existing CLI in `bin\` for the style (`bin\collect-projects.php`), and one test using fixture roots and a fake `ProcessRunner`.

## Context
- Inputs and their paths: asks in `C:\Users\roban\.claude\pm\asks.md` (one line each; a line containing `CLOSED` is closed); conch holder `C:\Users\roban\.claude\pm\conch.json` (`{project, path, since, by}`); conch refusals `C:\Users\roban\.claude\pm\conch-refusals.jsonl` (`{at, cwd, session_id, tool}`, written by card 11; absent today, which is no finding); go pending `%LOCALAPPDATA%\cas-voice\go-pending.json` (card 5; absent today). Every path is a constructor argument so tests point at fixture roots.
- Sizes and their time limits for `card_oversized`: Small 20, Normal 60, Risky 120 minutes, the size read from the brief's title `(Small|Normal|Risky)`. A Small or Normal brief naming a risky path from its project's `project.md` is oversized too.
- Never read a `.key`, token, `.credentials`, `.env` or settings file, and never copy their contents; the test proves the output carries nothing from one placed in the fixture.
- No spawn opens a window: anything run goes through the existing `ProcessRunner` (hidden). No powershell, cmd or python started from a test.

## Start here
- New: `src\Pm\PmState.php` (class `PmState`, constructor takes the roots and collaborators, `build(int $now): array`, and records every path it opens in a public `$opened` list), `bin\pm-state.php` (`--out <file>` required; writes atomically), `tests\PmStateTest.php`.
- Done-test command: `c:\xampp\php\php.exe tests/run.php --filter PmState`

## Files you may touch
- `src/Pm/PmState.php` (new)
- `bin/pm-state.php` (new)
- `tests/PmStateTest.php` (new)
- `tests/fixtures/pm-state/` (new, invented data only)
- `tests/TasksScriptTest.php` (round 2: on-demand list only)

## Done-test
`c:\xampp\php\php.exe tests/run.php --filter PmState` passes. New fixture tests prove: a live lock with loop.log 31 min old is `loop_stalled` and 29 min is not; a Normal card at 61 min is `card_oversized`; a Small brief naming `src/Http/` is `card_oversized`; a 5-min-old conch refusal is `chrome_without_conch`; a 35-min idle session with an open ask is `idle_with_open_work`; CLOSED asks are left out; nothing outside the fixture roots is opened (`$opened`); the output contains nothing from a `.key`, token or settings file.

Mutation check through `node C:\Users\roban\.claude\bin\project-test.cjs`: break the `loop_stalled` age check and the CLOSED filter in turn, run filtered, name the tests that failed, restore, green.

## Boundaries
No commit, no push, no install, nothing outward. Read-only on every input. PHP 8.2 only. `project.md`'s Never list applies.

## Progress
Keep `progress.md` beside this file from your first step, one line per step that changes a file or finishes a unit of work: done, files, next, anything half-written. Already there? You are resuming: read it and check the disk against it first.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests added, the mutation result naming the tests that failed, and any call made and why. Say what was not done.

## Round 2
The full suite failed: `TasksScriptTest: every bin/*.php job has a task with its interval...` lists every `bin/*.php` and expects each in `TASKS_EXPECTED`; `pm-state.php` is not scheduled, because plan card 10's PM check calls it on demand. Fix in the test, not with a scheduled task: add a `TASKS_ON_DEMAND` list beside `TASKS_EXPECTED` (`'pm-state.php' => 'called by the voice PM check, plan 130 card 10'`) and leave those names out of the coverage comparison. Do not touch `bin/register-tasks.ps1` or loosen any other assertion. Then run `--filter TasksScript` and `--filter PmState` green through the runner. The rest of your round-one work stands.

Extra file you may touch:
- `tests/TasksScriptTest.php`
