# 111. Cockpit fixes from the first live look (Risky)

## Objective
The live cockpit loads fast and never sits on skeletons, every panel reads
cleanly, and the top bar is plain text. Fixes found by looking at
`http://127.0.0.1:8787/cockpit.php` on 2026-11-01 after cards 107 and 108.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 111
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\107-cockpit-live\result.md`, `108-cockpit-daily\result.md`
- `D:\Dev\htdocs\_kc-dashboard\src\Cockpit\` (all), `public\cockpit\js\` (all), `public\api\cockpit.php`
- `D:\Dev\htdocs\_kc-dashboard\bin\collect-facts.php` (line ~37, where collectors are listed)
- `D:\Dev\htdocs\_kc-dashboard\data\app.log` (last 50 lines: the errors below)

## Context (each item is measured, not guessed)
1. Slow snapshot: `api/cockpit.php` takes 5.4 to 5.9 s per call (curl, three
   runs, 70-79 KB). The page polls every 10 s and headless Chrome often still
   showed skeletons after 15 s. Target: under 500 ms. Do the indexing and the
   daily block on the collector run (`bin/collect-facts.php`, already
   scheduled) or a cached build with a short TTL, and have the API answer from
   the cache; `NowService` already has its own cache. Measure before and after
   and put both numbers in result.md.
2. Never stuck: if the first snapshot has not arrived in 4 s, panels show
   "Still loading" text in place of skeletons; on an API error they show
   "Could not load, retrying" and keep polling. Skeletons only for the first 4 s.
3. Finance parser throws on every request: app.log repeats
   `cockpit daily: finance fact 0 needs id, kind and subject`. kc-finance's
   alerts.json has kinds payment-review, payment-split, todo, offsite-stale. Read
   it tolerantly (skip a fact that lacks a field, never throw for the panel);
   the panel shows the alerts it does have as lines (kind as plain words,
   subject, date) under the figures, and figures it lacks as a dash.
4. Plan limits: with no 5-hour reading the bar renders full while the value is
   "--". Unknown means an empty bar. Also look for `rate_limit_event` readings
   in all transcripts under `C:\Users\roban\.claude\projects\` (newest files
   first, stop at the first reading), not only loop pass files; one was found
   in `C--Users-roban` and `c--Users-roban-kc-finance` subagent transcripts.
5. Weather never fetches: 108 left the hookup out. Add the Weather collector to
   `bin/collect-facts.php` beside Finance (108's result.md gives the line).
6. Agent talk shows raw brief paths ("D:\Dev\htdocs\kc-portal\.claude\work\14o-client-message-mail\brief.md
   Do the Round 2 section..."). Replace a leading brief path with the card label
   ("card 14o, client message mail") and keep the rest of the text.
7. Top bar: Rob, 2026-11-01: "this can just be text of the date and current
   time - no need for pills or anything." Replace the clock pill and the RB
   avatar with plain text, e.g. "Sunday, November 1 · 1:58 PM" (12-hour, no
   seconds, no leading zero), muted, no border, no background.
8. Needs you lists 16 items, mostly parked cards. Sort newest first, show 6,
   and a "16 waiting, show all" text button that expands the list.
- It only ever runs in Chrome on Rob's desktop (landscape or portrait second
  monitor). No phone work.

## Start here
`node C:\Users\roban\.claude\bin\project-test.cjs -- c:\xampp\php\php.exe tests/run.php --filter Cockpit`

## Files you may touch
- `src/Cockpit/` (all files)
- `public/api/cockpit.php`
- `public/cockpit/js/`, `public/cockpit/cockpit.css`
- `bin/collect-facts.php`
- `tests/CockpitTest.php`, `tests/CockpitDailyTest.php`, `tests/CockpitFixesTest.php` (new)
- `tests/fixtures/cockpit/`, `tests/fixtures/cockpit-daily/`
- `.claude/work/111-cockpit-fixes/progress.md`, `.claude/work/111-cockpit-fixes/result.md`

## Done-test
From plan.md card 111, word for word:

`c:\xampp\php\php.exe tests/run.php --filter Cockpit` passes, including new
CockpitFixes tests proving: the snapshot API answers from cache without
re-indexing (a second call does no transcript read); a finance file with facts
missing fields returns the good facts and no exception; an unknown 5-hour
reading renders as an empty bar; a message starting with a brief path is
shown with the card label in its place; the top bar markup has no clock pill
or avatar; Needs you shows at most 6 items until expanded.

Then the mutation check (break the cache hit, then the tolerant finance read),
name the failing tests, restore, green. Filtered runs only.

## Boundaries
No commit, no push, nothing outward. No new dependency. Never list applies.

## Progress
Keep `progress.md` beside this file, one line per step. Already there? Resume
from it after checking the disk.

## Report
`result.md` under 200 words: files, tests, the before and after snapshot
times, the mutation result. Say what was not done.
