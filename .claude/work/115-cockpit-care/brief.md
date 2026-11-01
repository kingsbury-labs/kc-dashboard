# 115. Cockpit Care tab: care-plan clients at a glance, alerts when down (Risky)

## Objective
A Care tab in the cockpit shows every care-plan client in one glance row each
(green, amber, red) and opens a client, or all checks, for depth. When a site
goes down or kc-care raises an alert, the overview shows a red banner and
Chrome raises a notification, once per incident.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 115
- `D:\Dev\htdocs\kc-care\CLAUDE.md`, `D:\Dev\htdocs\kc-care\docs\PLAN.md`, `D:\Dev\htdocs\kc-care\clients.json` (read-only; never edit kc-care)
- `D:\Dev\htdocs\_kc-dashboard\src\Cockpit\`, `public\cockpit\js\` (routing, tabs), `src\Process\ProcessRunner.php`, `config\executables.php`, `src\Collectors\SslExpiry.php`

## Context
- Rob, 2026-11-01: "Anybody who is a care monitor, I should be able to glance
  for quick health checks, and go to individual/all items for in-depth. In
  addition, I should get a notice if something goes down. Paid care packages
  need full time monitoring."
- The 24/7 monitoring already exists in kc-care: UptimeRobot checks each site
  from outside every 5 minutes (and emails Rob), and kc-care's daily GitHub
  Actions run opens an issue in the private repo `rob-kingsbury/kc-care` for any
  finding (SPF, DMARC, SSL, domain expiry, page fingerprint changes). The
  dashboard reads those; it does not monitor on its own and it never writes to
  kc-care, UptimeRobot or GitHub.
- Sources, all on the collector run, cached:
  - Clients: `D:\Dev\htdocs\kc-care\clients.json` (key, domain, platform).
  - Up/down, response time, 7- and 30-day uptime: UptimeRobot v2 `getMonitors`
    (POST https://api.uptimerobot.com/v2/getMonitors, `custom_uptime_ranges`),
    API key read from `D:\Dev\htdocs\.credentials\kc-care-uptimerobot-key`
    (path in config only; added to Log redaction).
  - Open alerts: `gh issue list -R rob-kingsbury/kc-care --state open --json
    number,title,createdAt,url,labels` through `ProcessRunner`; add `gh`
    (`C:\Program Files\GitHub CLI\gh.exe`, check the real path) to
    `config/executables.php`. Nothing else is run.
  - SSL days left: the existing SslExpiry collector for each client domain.
- Glance row: client name, domain, status dot, response ms, 30-day uptime %,
  open alerts count, SSL days. Red = monitor down or an open alert labelled
  critical; amber = any open alert or SSL under 21 days; else green.
- Notice: when a client goes red, a red banner at the top of the overview
  ("brucemechanical.com is down, 4 min") and one Chrome Notification (ask
  permission once from a button, never on load); the incident id is kept in
  localStorage (try/catch) so it notifies once. Voice will read it later (card 110).
- Portrait monitor only (Rob's second monitor, Chrome).

## Files you may touch
- `src/Cockpit/` (new CockpitCare.php and wiring), `src/Collectors/` (new Care collector)
- `config/collectors.php`, `config/executables.php`
- `public/api/cockpit.php`, `public/cockpit/js/`, `public/cockpit/cockpit.css`
- `tests/CockpitPortraitTest.php` (added at round two: the new overview card must be in its column list)
- `bin/collect-facts.php`
- `tests/CockpitCareTest.php` (new), `tests/fixtures/cockpit-care/` (new)
- `.claude/work/115-cockpit-care/progress.md`, `result.md`

## Done-test
From plan.md card 115, word for word:

`c:\xampp\php\php.exe tests/run.php --filter CockpitCare` passes: against
fixture clients.json, a stubbed UptimeRobot response and a stubbed gh runner
(no network, no real process), the snapshot's care block lists each client with
status, uptime, response time, alert count and SSL days; a down monitor makes
that client red and raises one incident; the UptimeRobot key never appears in
the log or the API output; the only command the care collector can run is
`gh issue list` against `rob-kingsbury/kc-care`.

Then the mutation check (treat down as up, then let the runner accept another
gh subcommand), name the failing tests, restore, green. Filtered runs only.

## Boundaries
No commit, no push. Read-only against kc-care, UptimeRobot and GitHub. Never list applies.

## Progress
Keep `progress.md` beside this file, one line per step. Resume from it if present.

## Report
`result.md` under 200 words: files, tests, mutation result. Say what was not done.
