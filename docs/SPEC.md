# KC Dashboard spec

Rob's personal Chrome new-tab page for Kingsbury Creative. Runs only on his PC,
under XAMPP, at `http://127.0.0.1:8787/` on its own vhost whose DocumentRoot is
`public/`, the only folder reachable over HTTP. Nobody else uses it. It is
built to be a short list of what needs Rob, plus a live view of his projects.

## Decisions already made (Rob, 2026-10-12, revised 2026-10-17 and 2026-10-19)

- Local XAMPP only. Never deployed, never reachable from outside the PC.
- Every project path is under `D:\Dev\htdocs`. XAMPP itself (`C:\xampp`,
  `php.exe`, Apache) did not move.
- The "ask Claude" box runs `claude -p --model sonnet` (Rob's account, no API
  key) with the working directory set to the project he picks from
  `D:\Dev\htdocs\*`, or his home folder when none is picked. Every model call
  in this project is `claude -p`; the no-API rule stands until Rob lifts it.
- Mail is out of scope. The dashboard never reads, writes, flags, moves or
  deletes mail, including kc-finance's billing mailbox.
- The attention engine is the main feature (see Attention engine below).
- The local model is not on the critical path (see Later). Nothing waits on it.
- 2026-10-12: Apache runs from the XAMPP control panel as Rob (no Apache
  Windows service is registered), so it can start `claude` and `wt.exe` in
  his session directly.
- 2026-10-12: Python 3.11.9 is on PATH with the packages
  `bin/collect_ga.py` imports (google-analytics-data, google-api-python-client,
  google-auth, requests) already installed.
- 2026-10-12: `claude` is the npm install; the real binary is
  `%APPDATA%\npm\node_modules\@anthropic-ai\claude-code\bin\claude.exe`.

## Stack

- Vanilla PHP, runs on XAMPP's PHP 8.2 (`c:\xampp\php\php.exe`). No framework,
  no Composer, no npm build. Plain HTML, CSS and ES-module JS, mobile first,
  with motion and desktop layout layered on.
- SQLite (`data/dashboard.sqlite`, gitignored) for facts, news items, feedback
  labels, job queue and settings.
- Background jobs are PHP CLI scripts under `bin/`, run by Windows Task
  Scheduler as Rob, with scheduled tasks registered to run S4U (no stored
  password, runs whether or not Rob is logged on). The page never blocks on a slow fetch; it reads SQLite.
- Tests: a plain PHP test runner (`tests/run.php`), no PHPUnit. Network,
  LLM and `claude` calls are behind interfaces with fakes in tests.

## Security (the part that matters)

Any website open in Chrome can send requests to `localhost`. An endpoint that
runs `claude -p` is code execution on Rob's PC if it can be reached that way.

- Apache listens on loopback only, and `httpd.conf` denies `.credentials`
  (both 2026-10-12). The vhost's DocumentRoot is `public/`, so `data/`,
  `bin/`, `src/`, `tests/`, `llm/` and `config/` are not reachable at all.
- Every request checks that `REMOTE_ADDR` is loopback and that `Host` is
  exactly `127.0.0.1:8787` or `localhost:8787` (blocks DNS rebinding).
- Every state-changing or job-starting endpoint also requires POST, a
  per-install secret token (generated on first run into `data/`, never
  committed) sent in a header, and an `Origin` that is exactly
  `http://127.0.0.1:8787` or `http://localhost:8787`.
- One ProcessRunner starts every child process. It accepts only an absolute,
  allowlisted `.exe` path and refuses `.bat` and `.cmd`, because PHP 8.2.12
  predates the 8.2.18 `proc_open` fix.
- Junctions are rejected by comparing `realpath` of the target to the allowed
  root, and the check is repeated at launch.
- `claude -p` on untrusted data runs in an empty temp folder with tools
  disallowed.
- The project picker only accepts folder names that exist directly under
  `D:\Dev\htdocs`, matched against a fresh listing, plus the exact extra
  folders in a config list (kc-finance is one), each still checked by the
  same junction rule; no paths from the client.
- `claude -p` runs without permission bypass flags. The prompt goes in on stdin
  or as one escaped argument, never through a shell string.
- Credentials are read from `D:\Dev\htdocs\.credentials\` or Infisical at
  run time and never copied into this repo, the database, logs or the page.
- News text, finance evidence text and any file content shown on the page are
  untrusted. They are escaped on output and wrapped as data in every LLM
  prompt. The code validates every id and URL a model returns; a
  model-written URL is never rendered.

## Attention engine

A short "needs attention" list on the new tab: things across Rob's business
that need him, each with a verb, a date, one line of why and a link to the
source. The system discovers alert types from his feedback; he does not have
to list them.

- **Facts.** A `facts` table: id, source, kind, subject, salient hash, due
  date, severity, url, collected_at, state (new, shown, held, snoozed,
  dismissed, acted). Observations are append-only, with provenance (evidence
  span and parser version).
- **Rule collectors.** Code owns anything with a date or a CVE and always
  shows it: SSL expiry, endoflife.date, due dates from
  `kingsburycreative/clients/_DASHBOARD.md` and `_PIPELINE.md`, and the
  finance feed. Run by `bin/collect-facts.php` every 30 minutes.
- **Asymmetric model rule.** A model may add or rank candidates. A model "no"
  never deletes an item or is the only reason one is hidden. Held items go to
  a visible "also seen" fold.
- **Display budget.** Top five. Each item shows verb, date, evidence, source
  and a snooze. An item re-shows only when its state changes (30, 7, 1 days
  out, or severity up).
- **Daily read.** One `claude -p --model sonnet` call a day (6 am) over new
  facts plus running state by id, never the whole table, with a JSON schema,
  id validation and the top-five cap.
- **Feedback with reasons:** useful, already knew, not actionable, too early,
  irrelevant, wrong evidence, resolved. No click is never a negative. Labels
  are stored with the prompt hash.
- **Coverage health.** The page shows the last successful run per source,
  failures and stale collectors. A source with no good run is shown as a gap,
  not as "nothing to report".

## kc-finance integration

The dashboard reads one file and nothing else from kc-finance:
`C:\Users\roban\kc-finance\alerts\alerts.json`, written atomically by
kc-finance's scheduled runs and pinned by `config/alerts.schema.json` there.

- Shape: `generated_at`, `schema`, `sources` (name, last_ok_at, status) and
  `facts` (id, kind, subject, due_date, severity, amount_cents, evidence,
  url). Fact ids are stable across runs; shown, snoozed and acted state is
  keyed on them.
- `generated_at` older than 26h marks the finance source stale in coverage
  health.
- A fact's `url` is rendered only if it starts with `http://127.0.0.1:8000/`.
- `subject` is the Bigcapital customer display name; an alias map on our side
  covers names that differ from `_DASHBOARD.md`.
- Rob's finance to-dos arrive as `kind: "todo"` facts, with no separate
  tracking.
- The following are never stored in the dashboard's database or page: credentials or `.env`
  values, bank or card account numbers, raw bank CSV lines or transaction
  histories, receipt files or contents, Rob's salary or personal income. Client
  names, invoice numbers and amounts are fine. Display-only numbers (open
  receivables, projected year-end tax, TD balance at last statement, tax
  reserve against projected tax) are shown but never raise attention.
- Read-only. The dashboard never writes to kc-finance files or Bigcapital.
  Actions go through the Ask Claude box with cwd `C:\Users\roban\kc-finance`;
  when kc-finance's own hook refuses (its loop is mid-card) the box says
  "finance busy, try later". Prompts there set `MSYS_NO_PATHCONV=1` or use
  PowerShell.
- Ports 8000 and 8001 (Bigcapital) and scheduled tasks starting `KC Finance`
  belong to kc-finance. Ours are under `\KC Dashboard\`.

## Features

1. **New tab.** A tiny unpacked Chrome extension (`chrome-extension/`) that
   overrides the new tab and loads the dashboard. Google search box at the top
   (form GET to `https://www.google.com/search`).
2. **Ask Claude.** Text box, project picker (`D:\Dev\htdocs` folders plus
   configured extras), submit. The job is queued; a worker runs
   `claude -p --model sonnet --output-format json` in that folder and the
   answer appears on the page when done. Last 20 Q&As kept.
3. **Clients.** Live clients and CRM leads, parsed from
   `kingsburycreative/clients/_DASHBOARD.md` and `_PIPELINE.md`. Each client
   opens a panel: phase, next milestone, blocker, links (site, GA4, Search
   Console, billing, repo, client folder). Links come from a
   `config/clients.php` overrides file Rob edits.
4. **Attention list.** The engine above, with the "also seen" fold, snooze,
   feedback buttons and the coverage strip.
5. **News.** RSS/Atom/JSON feeds for AI, dev, devops and security
   (`config/feeds.php`). Items are clustered by story; a story's score rises
   with the number of distinct sources covering it, Hacker News points (from
   the Algolia API) and recency. Paginated, hottest first, filter by topic.
   Impact tags per project are Later.
6. **Numbers for every property.** GA4 and Search Console for each property,
   not only KC (reuse this repo's `bin/collect_ga.py`, which reads only the
   metrics and uses the service account, run on a schedule locally),
   `status.sembr.co/api/status` and Bugsink error counts.
7. **Project scanner and map.** Scans `D:\Dev\htdocs` plus configured extras
   for each project's branch, dirty count, last commit age and loop state.
   One tile per project on the page.
8. **What's running.** A strip at the top: Claude Code sessions busy or
   waiting, running loops with their card, failed scheduled tasks and dev
   server ports.
9. **KC Command Center.** The new tab is a JARVIS-style command center,
   always dark and animated, built to Rob's sample (design brief:
   `.claude/work/mission-control.md`). A central AI core (a canvas wireframe
   globe with orbit rings) has every agent connected to it: Claude Code
   sessions, project loops and collectors, each with its own live tile. Around
   it: AI core overview, live intelligence feed (attention), mission timeline,
   quick commands, system monitor gauges, memory insights, LLM status and a
   Talk to Jarvis bar. Real data only; a panel with no source says "not
   connected". Reduced motion draws one still frame; with no JS every panel
   renders from the server and a static core shows.
10. **Wall page.** `/wall.php`, the command center full screen for a second
    monitor. Screen Wake Lock; the only page that streams.
11. **Vault.** Plain Markdown under `D:\Dev\vault\kc` that Obsidian opens.
    Per-project notes, canvas export and a board file. Obsidian draws the
    graph and kanban; the dashboard does not.
12. **Briefing, memory and history.** A "Brief me" briefing from the
    attention list, numbers and news, written to the vault, with memory notes
    and history.
13. **Voice out.** The browser's `speechSynthesis` reads the briefing and
    answers, local voices only, so no audio leaves the PC.
14. **Extras.** Upcoming due dates from `_DASHBOARD.md`; SSL expiry for client
    domains (as facts).

## Later (not built now)

- Local model for bulk work: Ternary-Bonsai-2-27B (PTQ1_0) on the PrismML
  llama.cpp fork's `llama-server`, only if it gets a job. Runs as
  `llm/fork/llama-server.exe -m <gguf> -ngl 99 -fa on -c 8192 -np 1
  --cache-type-k q8_0 --cache-type-v q8_0 --jinja --host 127.0.0.1
  --port 8081`, loaded on demand with a canary prompt before each batch.
  Requests send `chat_template_kwargs: {enable_thinking: false}` and a
  `json_schema` `response_format`. It may only add candidates, never be the
  matcher of record. When it is down, jobs wait; there is no fallback model.
  A weekly check against the newest fork release and model would flag an
  update and never download on its own.
- Impact tags: each news story checked against project profiles.
- OSV-Scanner over lockfiles, rule promotion (a pattern useful three times
  becomes a code predicate), moving the daily read to the API if Rob lifts
  the no-API rule, a finance approval file.
- JARVIS round two: push-to-talk, a local voice router, voice handoffs to
  `claude -p`, wake word, loop kanban and graph views, `gh` counts, Claude
  Code hooks feeding the running strip. Meta Ads and Buffer numbers.

## Out of scope

Anything involving mail, any write to Stripe/GA/clients' sites or to
kc-finance, hosting this anywhere but localhost, multi-user anything.
