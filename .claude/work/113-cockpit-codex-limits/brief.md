# 113. Cockpit: Codex limits beside Claude's, stale sessions dropped (Normal)

## Objective
Plan limits shows Rob's Codex 5-hour and weekly use beside Claude's, each with
its reset time and reading age. Working now stops listing sessions and loops
that have already finished.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 113
- `D:\Dev\htdocs\_kc-dashboard\src\Cockpit\CockpitDaily.php` (`usage`), `src\Cockpit\CockpitApi.php`, `src\Cockpit\CockpitDeps.php`
- `D:\Dev\htdocs\_kc-dashboard\public\cockpit\js\views.js` (plan limits and Working now)

## Context
- Rob, 2026-11-01: "I have codex as well. It would be nice to see my limits
  and usage for that as well."
- Source, checked: Codex CLI session logs at
  `C:\Users\roban\.codex\sessions\YYYY\MM\DD\rollout-*.jsonl`. Their token_count
  events carry `"rate_limits":{"limit_id":"codex","primary":{"used_percent":23.0,
  "window_minutes":300,"resets_at":1793568898},"secondary":{"used_percent":4.0,
  "window_minutes":10080,"resets_at":...},"credits":{...}}`. Read the newest
  file's last reading (newest files first, stop at the first reading); never
  read anything else from those files. `resets_at` is unix seconds.
- Plan limits panel: two groups, "Claude" (as now) and "Codex", each with the
  5-hour and weekly bars, percent, reset time and the reading's age; muted past
  30 minutes. No cost, no credits balance.
- Stale Working now: seen live 2026-11-01, "_kc-dashboard loop 4c6f5b, working,
  Work 1 of 4" stayed listed after that loop run had finished, and old
  interactive sessions 2 to 5 hours old show as working. An entry is working
  only while its process is alive (NowService session status or the loop's lock
  with a live pid); otherwise it leaves Working now.

## Start here
`node C:\Users\roban\.claude\bin\project-test.cjs -- c:\xampp\php\php.exe tests/run.php --filter Cockpit`

## Files you may touch
- `src/Cockpit/` (all), `public/cockpit/js/`, `public/cockpit/cockpit.css`
- `config/running.php` (add `codex_sessions_dir`)
- `tests/CockpitCodexTest.php` (new), `tests/fixtures/cockpit-codex/` (new), `tests/CockpitTest.php`
- `.claude/work/113-cockpit-codex-limits/progress.md`, `result.md`

## Done-test
From plan.md card 113, word for word:

`c:\xampp\php\php.exe tests/run.php --filter Cockpit` passes, including new
CockpitCodex tests proving: against two fixture Codex session files, the
snapshot's usage block has a codex entry with the newest file's primary and
secondary percent and reset times; no other field from those files reaches the
API output; a loop whose lock pid is dead and a session whose process is gone
are not in Working now.

Then the mutation check (read the oldest file first, then keep a dead-pid
loop), name the failing tests, restore, green. Filtered runs only.

## Boundaries
No commit, no push, nothing outward. Codex files are read-only. Never list applies.

## Progress
Keep `progress.md` beside this file, one line per step. Already there? Resume
from it after checking the disk.

## Report
`result.md` under 200 words: files, tests, mutation result. Say what was not done.
