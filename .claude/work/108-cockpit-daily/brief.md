# 108. Cockpit daily panels: weather, AI news, kc-finance, plan limits (Risky)

## Objective
Four of the cockpit overview's "Not connected yet" panels show real data:
weather for Arnprior, AI news headlines as links, the kc-finance strip, and the
Max plan's 5-hour and weekly limits. All of it is cached server side, so a page
load never waits on the network and a failed fetch shows the last good reading
with its age.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 108
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\107-cockpit-live\result.md` (what 107 built and where)
- `D:\Dev\htdocs\_kc-dashboard\src\Cockpit\` and `public\cockpit\js\` (from 107)
- `D:\Dev\htdocs\_kc-dashboard\src\Collectors\Collector.php`, `src\Collectors\Finance.php`, `config\collectors.php`
- `D:\Dev\htdocs\_kc-dashboard\src\News\FetchNews.php`, `public\api\news.php`
- `D:\Dev\htdocs\_kc-dashboard\docs\AGENTOS.md` lines ~278-290 (usage source)

## Context
- Weather: Open-Meteo forecast API, no key, Arnprior ON (45.43, -76.35),
  current temp and condition, today's high and low, the next 5 hours. Fetched
  through the project's existing HTTP client (`src/Net/`), cached in the data
  folder, refreshed at most every 15 minutes, on the existing collector run, not
  on page load. The panel links to Environment Canada's Arnprior page.
- AI news: the newest 4-6 items from the news store FetchNews already fills,
  each with title, source, age and its URL as a link (target _blank,
  rel noopener). URLs must be http(s) only.
- kc-finance: whatever `Finance` collects from kc-finance's alerts file
  (`finance_path`), read only: receivables total, next due, reserve and the
  oldest overdue invoice if those fields exist; any field the file lacks shows
  as a dash, never invented.
- Plan limits: the newest `rate_limit_event` reading (`five_hour`,
  `seven_day` utilization with `resetsAt`) found in loop pass files
  (`<project>\.claude\work\loop\`) and transcripts, across the projects the
  dashboard knows. Show percent, reset time, and the reading's age; muted past
  30 minutes old.
- Rob does not pay per call (Max subscription): show no cost anywhere.

## Start here
Have not read 107's code yet (it does not exist at brief time). Start from
107's `result.md`, extend its snapshot (or add a `daily` block to it) rather
than a second endpoint if that keeps one guard path.
Done-test: `node C:\Users\roban\.claude\bin\project-test.cjs -- c:\xampp\php\php.exe tests/run.php --filter CockpitDaily`

## Files you may touch
- `src/Cockpit/` (new files and 107's files)
- `public/api/cockpit.php`
- `public/cockpit/js/`, `public/cockpit/cockpit.css`
- `src/Collectors/` (a new Weather collector only; do not change Finance)
- `config/collectors.php`
- `tests/CockpitDailyTest.php` (new), `tests/fixtures/cockpit-daily/` (new)
- `.claude/work/108-cockpit-daily/progress.md`, `.claude/work/108-cockpit-daily/result.md`

## Done-test
From plan.md card 108, word for word:

`c:\xampp\php\php.exe tests/run.php --filter CockpitDaily` passes: against
fixtures and a stubbed HTTP fetcher (no real network), the snapshot API returns
weather, news, finance and usage blocks with the fixture values; a failed
weather fetch returns the last cached reading with its age, never an error;
usage picks the newest reading across two fixture files.

Then the mutation check through the runner: break the cache fallback, then the
newest-reading pick (one at a time), name the tests that failed, restore, run
green. Filtered runs only.

## Boundaries
No commit, no push, nothing outward. No real network in tests. No new
dependency. `project.md`'s Never list applies.

## Progress
Keep `progress.md` beside this file from your first step, one line per step
that changes a file or finishes a unit of work. Already there? You are
resuming: read it and check the disk against it first.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, which fields were
missing from real sources. Say what was not done.
