# Plan: KC Dashboard (whole spec), revision 5

Source: `docs/SPEC.md` (card 59 brings it in line with this plan). Rules:
`.claude/project.md`, `CLAUDE.md`. Design: `.claude/work/attention-engine.md`.
Finance: `.claude/work/finance-contract.md`.

Revision 4 (2026-10-17, Rob approved the attention-engine changes): the
attention engine is the main feature. Mail is out (old 65, 66 deleted). The
no-API rule stays, so every model call is `claude -p`. Bonsai and the LLM
layer leave the critical path (old 61 and 64 go to Later); gpt-oss is gone.
Paths: `c:\xampp\htdocs` now means `D:\Dev\htdocs`; XAMPP itself did not
move. Revision 3's review mapping (which Codex and Fable findings each card
carries) is in git at `b0ed4b4:.claude/work/plan.md`; every card below keeps
the findings its revision 3 version carried.

Revision 5 (2026-10-19, Rob): the JARVIS layer from `jarvis-research.md` goes
on top of revision 4: project map, what's running, HUD and orb, wall page,
vault with briefings and memory, voice out (cards 73 to 80). Card 67 covers
every property, not only KC. The vault is plain Markdown that Obsidian can
open; Obsidian shows the knowledge graph and the kanban board, so the
dashboard does not build those two views. Voice in, the local model, voice
handoffs to Claude, wake word, Meta Ads and Buffer are in Later. Rob runs
this build agentically from a fresh window, not through `claude-loop.cjs`.
Build order is the order of the cards below: 59, 60, 62, 73, 74, 75, 76, 77,
70, 71, 72, 63, 67, 78, 76b, 79, 80, 68, 69.

Revision 5.1 (2026-10-19, Rob, after seeing the built pages): the UI must look
like the JARVIS command center in Rob's samples, dark, animated, with every agent
connected to a central AI core. Card 76b redoes `/` and `/wall.php` in that skin
before 79; cards 79, 80 and 68 build inside it. Design brief:
`.claude/work/mission-control.md`; samples in `design-refs/` (gitignored).

Every card leaves `c:\xampp\php\php.exe tests/run.php` green. Code cards use
fakes; no real network, model or process calls in tests.

## Done

- 01 test runner, 02 decisions, 03 models on disk, 04 spec, 05 logger, 06
  vhost snippet and HTTP check, 07 vhost installed, 09 fallback (now void),
  10 request guard and token, 11 responses and front controller, 12 job
  queue, 13 worker and registry, 13a runner fails on a broken test file, 14
  safe directory check, 15 ProcessRunner.

## Cards (in order)

### 59. Spec update for revision 4
- Role: implementer. Risky path: no. Depends: done cards only.
- `docs/SPEC.md`
- `tests/SpecTest.php`

Rewrite the spec to match this plan and `attention-engine.md`: remove mail
(IMAP, digest, mail credentials, mail tasks), gpt-oss, Bonsai on the
critical path and impact tags as a main feature; add the attention engine
(facts table, rule collectors, daily read, feedback, coverage health) and
the kc-finance integration from `finance-contract.md` (read-only
`alerts.json`, never-store list, `url` prefix rule, 26h staleness); Ask
Claude is `claude -p --model sonnet`; every `c:\xampp\htdocs` becomes
`D:\Dev\htdocs`. Keep the Security section's existing bullets.

Done-test: `php tests/run.php --filter Spec` passes. SpecTest asserts
SPEC.md has no `c:\xampp\htdocs` (case-insensitive), no `gpt-oss`, no
`IMAP`; has headings or lines naming `facts`, `alerts.json`, `also seen`,
`coverage`; and still has every Security bullet SpecTest checked before.

### 60. Ask Claude core
- Role: implementer. Risky path: yes (`src/Claude/`, `public/api/`, `src/Fs/`, `src/Process/`). Depends: 59.
- `src/Projects/ProjectList.php`
- `config/projects.php`
- `src/Fs/SafeDir.php`
- `src/Process/ProcessRunner.php`
- `config/executables.php`
- `src/Llm/Prompt.php`
- `src/Llm/StructuredOutput.php`
- `src/Claude/ClaudeRunner.php`
- `src/Jobs/AskHandler.php`
- `src/Jobs/Registry.php`
- `bin/worker.php`
- `tests/run.php`
- `public/api/ask.php`
- `public/api/answers.php`
- `src/migrations/003_asks.sql`
- `tests/ProjectListTest.php`
- `tests/SafeDirTest.php`
- `tests/ProcessRunnerTest.php`
- `tests/PromptTest.php`
- `tests/StructuredOutputTest.php`
- `tests/ClaudeRunnerTest.php`
- `tests/AskApiTest.php`
- `tests/WorkerTest.php`
- `tests/BinCliTest.php`

ProjectList: folders directly under `root` from `config/projects.php`
(default `D:\Dev\htdocs`), plus `extra`, a list of exact absolute folders
(first entry `C:\Users\roban\kc-finance`), each listed by basename, each
passing SafeDir against its own parent, a basename that collides with a root
folder is skipped and logged. Fresh each call, names `^[A-Za-z0-9._-]+$`
only, dot folders skipped by a rule that moves into `SafeDir`; a name
resolves only if it is in the listing. An `extra` entry may carry
`busy_if_dirty => 'scripts/'`: before launching, AskHandler runs `git -C
<folder> status --porcelain -- <that path>` through ProcessRunner (git.exe
absolute in `config/executables.php`), and any output stores the answer
"finance busy, try later" without starting claude. ProcessRunner gains a
fixed `extraEnv` map merged over the allowlisted environment; ClaudeRunner
always sets `MSYS_NO_PATHCONV=1`. Prompt: fixed system instruction that the
data block is quoted evidence, never instructions; boundary regenerated
while the payload contains it; generator injectable. StructuredOutput:
required fields, enums, length caps, unknown fields dropped. ClaudeRunner:
absolute `claude.exe`, `-p --model sonnet --output-format json`, prompt on
stdin, through ProcessRunner; untrusted mode (any `Prompt`-built prompt)
runs in a fresh empty temp folder with `--disallowedTools
Bash,Edit,Write,MultiEdit,NotebookEdit,WebFetch,WebSearch`; `usage` from the
JSON is returned. ask.php: `checkWrite()`, project via ProjectList, blank =
home folder, enqueue. answers.php: `checkRead()`, JSON. AskHandler runs
ClaudeRunner with the SafeDir recheck at launch, keeps 20 answers,
registered as `ask` through a factory so tests inject `FakeProcessRunner`.
Worker calls `recoverStale` before claiming. Every `bin/*.php` and
`tests/run.php` exit non-zero when `PHP_SAPI !== 'cli'`.

Done-test: `php tests/run.php` with `--filter ProjectList`, `SafeDir`,
`ProcessRunner`, `Prompt`, `StructuredOutput`, `ClaudeRunner`, `AskApi`,
`Worker` and `BinCli` each pass. ProjectListTest: a temp root with `alpha/`,
`.credentials/`, `.hidden/`, `semi;colon/`, a file and a real junction, plus
one temp extra folder, lists `['alpha', <extra basename>]`; an extra that is
a junction, or collides with `alpha`, is not listed; `..`, `alpha/..`,
`alpha\sub`, `C:\Windows`, `.credentials`, the junction and an empty name
resolve to null. SafeDirTest: the volume-GUID junction test is rebuilt so
deleting the stat/lstat inode check fails it (log, card 14), and a dot
folder is rejected by SafeDir itself. ProcessRunnerTest: `extraEnv` reaches
the child and cannot be overridden by the parent environment. PromptTest:
with a seeded generator whose first three boundaries are planted in the
payload, the final boundary is absent from the payload and wraps it twice,
and the instruction precedes the block. StructuredOutputTest: extra fields
dropped, out-of-enum value or invalid JSON gives null, over-length reason
truncated. ClaudeRunnerTest: argv has `-p`, `--model sonnet` and
`--output-format json`, never `--dangerously-skip-permissions` or
`--permission-mode`; the prompt is in no argv item; env has
`MSYS_NO_PATHCONV=1`; untrusted mode sets an empty temp cwd and the
disallowed list; timeout returns a timeout error; `result` and `usage` are
parsed. AskApiTest: each card 10 rejection and an unknown project create
zero jobs, a valid request creates one, the handler stores the fake answer,
a 21st drops the oldest, `Registry` maps `ask`, a project swapped for a
junction after enqueue is refused at launch, a dirty `busy_if_dirty` path
stores "finance busy, try later" and starts no claude, answers.php refuses a
foreign Host. WorkerTest: a claim older than the timeout is recovered and
run. BinCliTest: every `bin/*.php` and `tests/run.php`, run through
`php-cgi.exe`, exits non-zero and runs nothing.

### 62. Page UI shell: search, Ask Claude, answers, clients
- Role: implementer. Risky path: yes (`public/api/`, `src/Http/`). Depends: 60.
- `public/index.php`
- `public/assets/app.js`
- `public/assets/app.css`
- `public/assets/clients.js`
- `public/api/clients.php`
- `src/View.php`
- `src/bootstrap.php`
- `src/Http/Response.php`
- `src/Sys/Elevation.php`
- `src/Clients/DashboardParser.php`
- `src/Clients/PipelineParser.php`
- `config/clients.php`
- `tests/Support/DomSinks.php`
- `tests/fixtures/clients/_DASHBOARD.md`
- `tests/fixtures/clients/_PIPELINE.md`
- `tests/fixtures/whoami/`
- `tests/PageTest.php`
- `tests/PageUiTest.php`
- `tests/ClientsParserTest.php`
- `tests/ClientsApiTest.php`
- `tests/ElevationTest.php`

Mobile first: base styles work on a phone with no JS; desktop layout layered
on with `min-width` queries. ES-module JS sends `X-KC-Token` on POST, polls
answers, writes only with `textContent`. Clients from
`D:\Dev\htdocs\kingsburycreative\clients\` (path in `config/clients.php`):
only the table under `## Active Projects`, markdown emphasis, arrows and
emoji stripped, due date ISO or null, links merged by slug, only `https://`
or `http://` links kept. Elevation: `whoami.exe /groups` through
ProcessRunner on page load; `High Mandatory Level` shows a warning banner.
Bootstrap adds a shutdown function so a true fatal returns the same JSON
500; `Response::emit` sends no body once headers are already sent. The
token appears on `/` only in its `<meta>` tag.

Done-test: `php tests/run.php` with `--filter Page`, `PageUi`,
`ClientsParser`, `ClientsApi` and `Elevation` each pass, and `php
ops/http-check.php` exits 0. PageUiTest: picker options come from
ProjectList; an answer holding `<script>` renders escaped; `DomSinks` finds
none of `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`,
`srcdoc`, `eval(`, `new Function` in `public/assets/*.js`; `app.css` has no
`max-width` media query. PageTest adds: a 403 never creates the token file;
a fatal in a child `php.exe` run of the front controller yields JSON 500
with no `#0 `; the token occurs exactly once in `/`'s body. ClientsParserTest:
the "Awaiting Rob's outreach" table adds no rows; five active rows with
phase, next milestone, blocker; leads parsed with bold stripped; `Election
2026-10-26` gives `2026-10-26`, `TBD` null; a missing slug gets no links; a
duplicate slug is reported. ClientsApiTest: foreign Host rejected; active
clients and leads with every field and six link kinds; due dates soonest
first; a `javascript:` link dropped. ElevationTest: fixture output with
`High Mandatory Level` sets the warning, `Medium` does not, a missing
`whoami` sets nothing and does not throw.

### 73. Project scanner
- Role: implementer. Risky path: yes (`src/Process/`, `src/Fs/`). Depends: 60.
- `src/Projects/Scanner.php`
- `src/Projects/GitStatus.php`
- `src/Projects/LoopState.php`
- `bin/collect-projects.php`
- `config/projects.php`
- `config/executables.php`
- `src/migrations/008_projects.sql`
- `tests/fixtures/projects/`
- `tests/ProjectScannerTest.php`
- `tests/LoopStateTest.php`

Roots: `D:\Dev\htdocs` plus card 60's extra folders. Never `C:\xampp\htdocs`:
66 of its entries are junctions into `D:\Dev\htdocs`, and SafeDir rejects
junctions. One `projects` row per direct child holding `.git`: slug, path,
branch, ahead, behind, dirty count, last commit sha, date and subject,
has_claude, loop state (none, running, stopped, stale), loop card and note
from `.claude/work/loop/status.json`, checklist cards and passing counts from
`.claude/work/checklist.json`, scanned_at, error. git runs through
ProcessRunner with `GIT_OPTIONAL_LOCKS=0`: `status --porcelain=v2 --branch`
and `log -1 --format=%H%x00%cI%x00%s`. A loop is running only when `lock`
holds a PID that is alive and is `node.exe` (`tasklist.exe /fo csv` through
ProcessRunner, injectable) and there is no `STOP`; a lock with a dead PID is
stale (henike-contracting has one today). The scanner only reads: it never
writes into any project. One project's failure is stored on its row and the
scan goes on. Commit subjects are data.

Done-test: `php tests/run.php` with `--filter ProjectScanner` and
`LoopState` pass. ProjectScannerTest, fake runner and fixture tree: three
repos give three rows; a folder without `.git` is skipped; a junction is
skipped; a porcelain v2 fixture gives branch, ahead 2, behind 1, dirty 3;
every git call carries `GIT_OPTIONAL_LOCKS=0`; a failing git on one repo
stores its error and the next repo is still scanned; the fixture tree's file
hashes are identical before and after a scan. LoopStateTest: lock with a live
`node.exe` PID and no STOP is running with the card from `status.json`; a
dead PID is stale; lock plus STOP is stopped; no lock is none; a checklist
of 5 entries with 3 passing gives 5 and 3; malformed `status.json` gives
running with no card, not an exception.

### 74. What's running
- Role: implementer. Risky path: yes (`public/api/`, `src/Process/`). Depends: 73.
- `src/Running/SessionRegistry.php`
- `src/Running/Ports.php`
- `src/Running/Tasks.php`
- `src/Running/NowService.php`
- `public/api/now.php`
- `config/running.php`
- `config/executables.php`
- `tests/fixtures/running/`
- `tests/SessionRegistryTest.php`
- `tests/NowApiTest.php`

Sources. Claude Code's session files in `C:\Users\roban\.claude\sessions\`
(path in config): undocumented and version-dependent, so the parser fails
soft. It lists only names matching `^\d+\.json$` and never opens anything
else (the folder also holds `.key` files). Keeps pid, cwd, status (busy,
idle, waiting), waitingFor, name, startedAt, updatedAt; drops every other
field. A session counts only when its PID is alive and the process start
time matches `procStart` within 2 seconds. cwd maps to a project slug from
card 73. Loops from card 73. Scheduled tasks under `\KC Dashboard\` from
`schtasks.exe /query /fo csv /v`. Listening ports from `netstat.exe -ano -p
tcp` joined to `tasklist.exe /fo csv`: port, program name, PID, never a
command line. `api/now.php` GET returns `{sessions, loops, tasks, ports,
at}`, Host-checked, cached 3 seconds so many open tabs cost one scan.

Done-test: `php tests/run.php` with `--filter SessionRegistry` and `NowApi`
pass. SessionRegistryTest on fixtures: a live session gives its project slug
and status busy; a dead PID is skipped; a reused PID (procStart mismatch) is
skipped; the reader's list of opened files never includes the fixture
`.key` file, and its marker string is absent from the output; unknown fields
are dropped; malformed JSON is skipped without an exception. NowApiTest:
foreign Host rejected; the four keys are present; a command-line marker
string in the fixture never appears in the response; two calls within 3
seconds on a fake clock run the scan once.

### 75. Project map and running strip
- Role: implementer. Risky path: yes (`public/api/`). Depends: 74.
- `public/api/projects.php`
- `public/assets/projects.js`
- `public/assets/now.js`
- `public/assets/app.css`
- `src/View.php`
- `tests/ProjectsApiTest.php`
- `tests/ProjectsUiTest.php`

The main page gains a running strip at the top (sessions busy or waiting,
running loops with their card, failed scheduled tasks, dev server ports) and
a project map: one tile per project with name, branch, dirty count, last
commit age, a loop badge with the current card and checklist progress. Tiles
sort running loop first, then dirty, then newest commit. With no JS, the
server renders both from the database. With JS, `now.js` polls
`api/now.php` every 5 seconds while the tab is visible and stops on
`visibilitychange`; `projects.js` refreshes tiles every 60 seconds. Mobile
first: one column, grid layered on with `min-width`.

Done-test: `php tests/run.php` with `--filter ProjectsApi` and `ProjectsUi`
pass. ProjectsApiTest: foreign Host rejected; rows in the stated order.
ProjectsUiTest: the no-JS page has one tile per fixture project and the
strip; a commit subject holding `<img src=x onerror=alert(1)>` renders
escaped; `now.js` holds `visibilitychange` and `5000` (grep); `DomSinks`
clean; `app.css` has no `max-width` media query.

### 76. HUD theme and orb
- Role: implementer. Risky path: no. Depends: 75.
- `public/assets/hud.css`
- `public/assets/orb.js`
- `src/View.php`
- `tests/HudTest.php`

The JARVIS look in plain CSS, no library: dark base, thin frame lines, cut
corners, monospace numbers, one accent colour; colours are tokens on
`:root` with a light theme under `prefers-color-scheme`. The orb is a canvas
2D ES module: radial glow, noise-displaced rings, `setLevel(0..1)` and
`setState('idle'|'thinking'|'speaking')`. `prefers-reduced-motion` draws one
still frame; the loop pauses while the tab is hidden; with no JS a static
CSS orb shows. The orb sits in the Ask Claude panel and shows thinking while
an answer is pending. Every panel is checked against Rob's design-slop list
(`c:/personas/cas/memory/design-slop.md`): no eyebrow labels, no filled and
outlined buttons side by side, no beige or cream, no comma in a heading.

Done-test: `php tests/run.php --filter Hud` passes. HudTest: `orb.js`
exports `setLevel` and `setState` and holds `prefers-reduced-motion`,
`visibilitychange` and `requestAnimationFrame` (grep); `hud.css` defines its
colours on `:root` and has a `prefers-color-scheme` block, no `max-width`
media query, no `letter-spacing` of 0.1em or more in a rule with
`text-transform: uppercase`; `View` output has the orb canvas and the no-JS
fallback element; `DomSinks` clean.

### 77. Wall page
- Role: implementer. Risky path: yes (`public/`). Depends: 76.
- `public/wall.php`
- `public/api/wall-stream.php`
- `public/assets/wall.js`
- `public/assets/wall.css`
- `tests/WallTest.php`
- `tests/WallStreamTest.php`

`/wall`: a full-screen HUD for a second monitor with the large orb, the
running strip, the project map, and the attention and headline panels when
their APIs exist (a missing API hides its panel, so cards 70 and 63 fill in
later). A Fullscreen button; Screen Wake Lock, re-taken on
`visibilitychange`. This is the only page that streams: `wall-stream.php`
sends an `event: now` every 5 seconds, sets `retry: 3000`, calls
`ob_end_flush()` and `flush()` after each event, and ends itself after 55
seconds because XAMPP's `max_execution_time` is 120 and PHP on Windows counts
wall-clock time. Host-checked, no PHP session. With no JS, `/wall` is a
stacked page that reloads every 30 seconds.

Done-test: `php tests/run.php` with `--filter Wall` and `WallStream` pass.
WallStreamTest runs `wall-stream.php` in a child `php.exe` with its run
length set to 2 seconds by an env value and asserts at least one `event:
now` block, a `retry:` line and an exit inside the limit; a foreign Host gets
403 and no stream. WallTest: the no-JS page has the panels and a refresh;
`wall.js` holds `EventSource`, `wakeLock` and `visibilitychange` (grep);
`DomSinks` clean.

### 70. Facts table and the attention list
- Role: implementer. Risky path: yes (`public/api/`). Depends: 62.
- `src/migrations/004_facts.sql`
- `src/Attention/FactStore.php`
- `src/Attention/Selector.php`
- `public/api/attention.php`
- `public/api/feedback.php`
- `public/assets/attention.js`
- `src/View.php`
- `tests/FactStoreTest.php`
- `tests/SelectorTest.php`
- `tests/AttentionApiTest.php`

`facts`: id (stable, from the source), source, kind, subject, salient hash,
due date, severity, amount_cents, evidence, url, parser version,
collected_at, state (new, shown, held, snoozed, dismissed, acted),
snoozed_until. Observations append; a re-collected id updates in place and
only re-surfaces when its salient hash changes, severity rises, or it
crosses 30, 7 or 1 days out. `sources` table: name, last_ok_at, last_error,
status. Selector: top five by severity then due date, the rest in an "also
seen" fold; nothing is ever dropped by ranking alone. Feedback reasons:
useful, already knew, not actionable, too early, irrelevant, wrong
evidence, resolved; each saved with the fact id and its salient hash.
Coverage health: a source whose `last_ok_at` is older than its own maximum
age (26h for finance) shows as stale. A `url` renders as a link only if it
is `https://`, or `http://127.0.0.1:` plus a port listed in config; else
text.

Done-test: `php tests/run.php` with `--filter FactStore`, `Selector` and
`AttentionApi` each pass. FactStoreTest: the same id twice is one row; an
unchanged hash keeps state `dismissed`, a changed hash returns it to `new`;
a snooze hides it until `snoozed_until` on a fixed clock; 30/7/1-day
crossings re-surface it once each. SelectorTest: seven facts give five
shown and two in "also seen"; high beats med beats low, then sooner due
date. AttentionApiTest: foreign Host rejected; a feedback POST without the
token saves nothing; a valid POST saves one label with the hash; an
unknown reason is refused; a `javascript:` url and an `http://127.0.0.1:9999/`
url come back as null; a stale source is flagged; `DomSinks` clean.

### 71. Rule collectors
- Role: implementer. Risky path: no. Depends: 70.
- `src/Net/HttpClient.php`
- `src/Net/CurlHttpClient.php`
- `src/Collectors/Collector.php`
- `src/Collectors/SslExpiry.php`
- `src/Collectors/EndOfLife.php`
- `src/Collectors/ClientDates.php`
- `src/Collectors/Finance.php`
- `bin/collect-facts.php`
- `config/collectors.php`
- `tests/Fakes/FakeHttpClient.php`
- `tests/fixtures/collectors/`
- `tests/CollectorsTest.php`
- `tests/FinanceCollectorTest.php`

Each collector returns facts and records its run in `sources`; one failing
collector does not stop the others. SslExpiry: hosts from config, reads the
peer certificate's `validTo` through an injectable fetcher, facts at 30/7/1
days and once expired (high). EndOfLife: products and installed versions
from config (first: PHP 8.2 for XAMPP), `endoflife.date/api/<product>.json`
through HttpClient, fact when support ends within 90 days or has ended.
ClientDates: due dates and follow-ups from `_DASHBOARD.md` and
`_PIPELINE.md` via card 62's parsers, overdue is high. Finance: reads
`C:\Users\roban\kc-finance\alerts\alerts.json` (path in config) read-only,
schema 1 only, maps each fact field for field (id prefixed `finance:`),
keeps only the contract's fields, sets the `finance` source's `last_ok_at`
from `generated_at`, and an alias map in config renames Bigcapital customer
names to client slugs. A missing, unparseable or other-schema file records
an error, not a crash. CurlHttpClient: connect and total timeouts, response
size cap, `https://` only.

Done-test: `php tests/run.php` with `--filter Collectors` and
`FinanceCollector` pass. CollectorsTest: a cert 6 days from expiry gives
one med fact and an expired one gives high; an endoflife fixture with PHP
8.2 ending 2026-12-31 gives a fact on a 2026-10-17 clock and none for a
version ending 2028; a `_DASHBOARD.md` fixture row overdue gives a high
fact; a throwing collector records its error and the next still runs;
CurlHttpClient refuses `http://` and `file://`. FinanceCollectorTest, on
fixtures: schema 1 file gives its facts with `finance:` ids; an extra field
(e.g. `account_number`) never reaches the database file (grep the file);
schema 2, truncated JSON and a missing file each record an error; a
`generated_at` 27h old marks the source stale; an aliased name maps to its
slug.

### 72. Daily read
- Role: implementer. Risky path: yes (`src/Claude/`). Depends: 71.
- `src/Attention/DailyRead.php`
- `bin/daily-read.php`
- `src/migrations/005_reads.sql`
- `tests/DailyReadTest.php`

One `claude -p` call a day through ClaudeRunner in untrusted mode: new
facts since the last read plus running state by id (never the whole table)
inside `Prompt`'s data block, a JSON schema of `{id, rank, reason}`,
checked by StructuredOutput. Code drops any id it did not send and never
stores a model-written URL. The model may rank and add a reason; a fact
the model leaves out or ranks last still shows (in "also seen" at worst).
Each read stores the prompt hash, the ids sent, the result and `usage`.

Done-test: `php tests/run.php --filter DailyRead` passes. DailyReadTest
with a fake runner: only facts newer than the last read are sent; an
invented id in the reply is dropped; a fact the reply omits is still
returned by Selector; a URL in a reason is not stored as a url; the prompt
hash and `usage` are saved; invalid JSON leaves the previous ranking.

### 63. News
- Role: implementer. Risky path: yes (`public/api/`). Depends: 71.
- `src/News/FeedParser.php`
- `src/News/FetchNews.php`
- `src/News/Clusterer.php`
- `src/News/Scorer.php`
- `bin/fetch-news.php`
- `config/feeds.php`
- `src/migrations/006_news.sql`
- `public/api/news.php`
- `public/assets/news.js`
- `src/View.php`
- `tests/fixtures/feeds/`
- `tests/FeedParserTest.php`
- `tests/FetchNewsTest.php`
- `tests/ClusterScoreTest.php`
- `tests/NewsApiTest.php`

RSS 2.0, Atom, JSON Feed, plus `hn.algolia.com/api/v1/search?tags=front_page`
for HN points, through card 71's `HttpClient`. Revision 5: `config/feeds.php`
starts from the AI, web dev, PHP and WordPress feeds checked in
`jarvis-research.md` section 3.4 (Anthropic through the Olshansk/rss-feeds
scrape, since Anthropic has no feed of its own).

Done-test: `php tests/run.php` with `--filter FeedParser`, `FetchNews`,
`ClusterScore` and `NewsApi` each pass. FeedParserTest: each fixture gives
title, URL, date, source, topic; Algolia fixture gives points; a malformed
feed is skipped; an XXE fixture yields no file content. FetchNewsTest: items
stored once across two runs; a `file://` or `ftp://` feed URL refused; an
oversized response skipped; a story URL starting `javascript:` or `data:`
stored as null. ClusterScoreTest: three sources on one story form one
cluster; two unrelated stories form two; the same URL with `utm_*` stripped
is one cluster; two items from one source count once; score rises with
sources and points and falls with age on fixed clocks. NewsApiTest: hottest
first, page size and page 2 offset, topic filter, foreign Host rejected;
`news.js` sets `rel="noopener noreferrer"` and assigns `href` only after an
`http:`/`https:` check (grep); `DomSinks` clean.

### 67. Numbers for every property
- Role: implementer. Risky path: yes (`public/api/`). Depends: 62.
- `bin/collect_ga.py`
- `src/Numbers/GaCollector.php`
- `src/Numbers/StatusCollector.php`
- `src/Numbers/BugsinkCollector.php`
- `bin/collect-kc.php`
- `src/migrations/007_numbers.sql`
- `public/api/numbers.php`
- `public/assets/numbers.js`
- `src/View.php`
- `tests/fixtures/numbers/ga_raw.json`
- `tests/CollectGaTest.php`
- `tests/NumbersTest.php`
- `tests/NumbersApiTest.php`

Unchanged from revision 3: own read-only script, not `dashboard-collect.py`;
GA4 sessions, users, top 10 pages; Search Console clicks, impressions, CTR,
position; last 28 days; read-only scopes; JSON on stdout; no file writes, no
Buffer, no GitHub, no `--index`. `--from-fixture <file>` runs only the
transform. Python through ProcessRunner. Bugsink token read from a path in
config, never a value. The finance display-only numbers (open receivables,
projected tax, TD balance, reserve vs tax) are not here; they wait for
kc-finance to add them to `alerts.json`.

Revision 5: every property, not only KC. `config/properties.php` lists, per
client slug, a GA4 property id and a Search Console site; one service
account, which Rob adds as a Viewer on each, reads them all. Rows are stored
per property, and one failing property stores its error while the others
still store. GA4 Realtime active users per property feed the wall page.
Added to the done-test: a two-property fixture stores two sets; a 403 on one
property stores its error and the other still stores.

Done-test: `php tests/run.php` with `--filter CollectGa`, `Numbers` and
`NumbersApi` each pass. CollectGaTest runs `python.exe bin/collect_ga.py
--from-fixture ...` through ProcessRunner and asserts the JSON keys, and by
grep that the script holds `analytics.readonly` and `webmasters.readonly`,
no `buffer`, no `--index`, no `open(` in write mode. NumbersTest: fixture
outputs stored; one failing source stores its error and the others still
store; the config holds the Bugsink token path, not a value.
NumbersApiTest: latest values returned, foreign Host rejected, `DomSinks`
clean.

### 78. Vault
- Role: implementer. Risky path: yes (`src/Fs/`, file writes). Depends: 73.
- `src/Vault/Vault.php`
- `src/Vault/ProjectNotes.php`
- `src/Vault/CanvasExport.php`
- `src/Vault/Board.php`
- `bin/write-vault.php`
- `config/vault.php`
- `tests/VaultTest.php`
- `tests/CanvasExportTest.php`
- `tests/BoardTest.php`

Vault root from config, default `D:\Dev\vault\kc`, created if missing; Rob
opens it in Obsidian. `Vault` passes the root through SafeDir, writes only
code-made paths inside a fixed set of folders (`Projects`, `Briefings`,
`Memory`, `Boards`, `Maps`) with `[a-z0-9-]` file names, writes atomically
(temp file in the same folder, then rename), and refuses `..`, absolute
paths and junctions. ProjectNotes: one `Projects/<slug>.md` per card 73 row,
front matter (branch, loop state, updated) and `[[client-slug]]` links from
a project-to-client map in config, so Obsidian's graph joins projects,
clients and briefings. A note is rewritten only when its content changed.
Text from git or a model is neutralised before it is written: `[[`, `]]`,
`obsidian://` and `file:` cannot survive as live syntax. CanvasExport:
`Maps/projects.canvas` per JSON Canvas 1.0, a file node per project note in a
grid and a group per loop state. Board: `Boards/Rob.md` in the Obsidian
Kanban plugin's format (front matter `kanban-plugin: board`, `## Backlog`,
`## Doing`, `## Done`), created once if missing and never overwritten.
`bin/write-vault.php` runs after `collect-projects.php`.

Done-test: `php tests/run.php` with `--filter Vault`, `CanvasExport` and
`Board` pass, all on a temp root. VaultTest: a write to `Projects/a.md` lands
with no temp file left; `../x.md`, `C:\x.md`, a folder outside the fixed set
and a junction root are refused; a commit subject holding `[[evil]]` and
`obsidian://open` is written neutralised; unchanged content leaves the mtime
unchanged. CanvasExportTest: the file parses as JSON; every node has `id,
type, x, y, width, height`; every edge's `fromNode` and `toNode` exist; no
two nodes overlap. BoardTest: a missing board is created with the three
columns and the front matter; an existing board's bytes are unchanged after
a run.

### 76b. KC Command Center
- Role: implementer. Risky path: yes (`public/`, `public/api/`, `src/Process/` via the CIM query). Depends: 63, 67, 70, 75, 76, 77, 78.
- `public/assets/hud.css`
- `public/assets/orb.js`
- `public/assets/core.js`
- `public/assets/app.css`
- `public/assets/app.js`
- `public/assets/now.js`
- `public/assets/projects.js`
- `public/assets/attention.js`
- `public/assets/news.js`
- `public/assets/numbers.js`
- `public/assets/wall.js`
- `public/assets/wall.css`
- `public/index.php`
- `public/wall.php`
- `public/projects.php`
- `src/View.php`
- `src/Running/NowService.php`
- `src/Running/Ports.php`
- `src/Running/SystemMonitor.php`
- `config/running.php`
- `config/executables.php`
- `tests/CommandCenterTest.php`
- `tests/SystemMonitorTest.php`
- `tests/HudTest.php`
- `tests/PageUiTest.php`
- `tests/ProjectsUiTest.php`
- `tests/WallTest.php`
- `tests/NowApiTest.php`
- `tests/ApiGuardSweepTest.php`
- `tests/fixtures/running/`

Rebuild the page skin and layout to `.claude/work/mission-control.md`, which
follows Rob's sample `design-refs/1-jarvis-command-center.png`. Always dark
(the light theme goes). Left rail, top bar with systems pill and live clock, the
central AI core as a canvas 2D wireframe globe with orbit rings and nodes (no
library), AI core overview, live intelligence feed (attention), active agents
(Claude sessions, project loops, collectors, each with a live sparkline while
busy and a light line to the core), mission timeline, quick commands (POST only),
system monitor gauges (CPU, RAM, disk from one PowerShell CIM query through
ProcessRunner, cached 10 s, served in `now.php`), memory insights, LLM status,
footer bar with the Talk to Jarvis capsule. Real data only; a panel with no
source says "not connected". The full project map moves to `public/projects.php`
as a dense table. The running ports list keeps only node, php, python and httpd
on ports from 1024 up plus 8787 and 8000 (allowed names in config). `/wall.php`
is the same page full screen without the rail. Mobile first: panels stack in
reading order on a phone; the rail and 3-column grid appear at 1024px.

Done-test: `php tests/run.php` with `--filter CommandCenter`, `SystemMonitor`,
`Hud`, `PageUi`, `ProjectsUi`, `Wall` and `NowApi` each pass, and `php
ops/http-check.php` exits 0. CommandCenterTest: the no-JS page has every panel
named in the brief (rail, systems pill, clock, core, core overview, feed, active
agents, timeline, quick commands, system monitor, memory insights, LLM status,
footer capsule) by id; a fixture with two sessions, one loop and one failed
collector renders four agent tiles with the right states; a panel with no source
renders "not connected"; every agent name and feed title holding
`<img src=x onerror=alert(1)>` renders escaped; quick commands are forms with
`method="post"` or buttons, never GET links that start work. HudTest: `hud.css`
has no `prefers-color-scheme: light` block and its `:root` background is dark;
`orb.js` or `core.js` holds `requestAnimationFrame`, `prefers-reduced-motion`
and `visibilitychange`; no `max-width` media query; no rule with
`letter-spacing` of 0.1em or more and `text-transform: uppercase`.
SystemMonitorTest: a CIM output fixture gives CPU, RAM and disk percentages; a
failing query gives "not connected" and no exception; two calls within 10 s on a
fake clock run the query once. NowApiTest adds: a netstat fixture with AVG,
Steam, svchost and a node dev server keeps only the node port. `DomSinks` clean.

### 79. Briefing, memory and history
- Role: implementer. Risky path: yes (`public/api/`, `src/Fs/`). Depends: 63, 67, 72, 78.
- `src/Briefing/Composer.php`
- `bin/brief.php`
- `src/Memory/MemoryStore.php`
- `src/migrations/009_memory.sql`
- `public/api/brief.php`
- `public/api/remember.php`
- `public/api/recall.php`
- `public/briefings.php`
- `public/assets/brief.js`
- `src/View.php`
- `tests/BriefingTest.php`
- `tests/MemoryTest.php`
- `tests/BriefApiTest.php`

Composer builds the day's briefing in code: the attention top five plus the
"also seen" count, loops and the cards they passed since yesterday, projects
with commits since yesterday, the top five headlines, numbers that moved.
Card 72's daily read supplies the words around it when there is one; the
code-built list always shows first, so a missing or wrong read hides
nothing. Written to `Briefings/YYYY-MM-DD.md` (one file per day, a rerun
overwrites only today's) and stored in SQLite. "Brief me" shows today's,
building it on demand if none exists. `briefings.php` lists past briefings
newest first and opens each as it was that day. Remember: a token-checked
POST of plain text up to 2,000 characters appends `- HH:MM text` to
`Memory/YYYY-MM.md` and an FTS5 row; `recall.php?q=` returns matches with
dates. In the Ask box, input starting `remember ` goes to remember by a code
match, not a model.

Done-test: `php tests/run.php` with `--filter Briefing`, `Memory` and
`BriefApi` pass. BriefingTest on fixtures and a fixed clock: the briefing
holds every attention fact id given even when the fake read omits two; a
second run the same day overwrites one file and leaves yesterday's
untouched; at most five headlines. MemoryTest: remember appends a line and
recall finds it by one word; 2,001 characters are refused; `[[x]]` and
`obsidian://` in the text are neutralised in the file. BriefApiTest: foreign
Host rejected; remember without the token saves nothing; history is newest
first; `DomSinks` clean.

### 80. Voice out
- Role: implementer. Risky path: no. Depends: 76, 79.
- `public/assets/voice.js`
- `public/assets/app.js`
- `src/View.php`
- `tests/VoiceTest.php`

The browser's `speechSynthesis` reads the briefing on "Brief me" and an Ask
Claude answer on a speak control. The voice picker lists only voices with
`localService` true, so no audio leaves the PC; the choice is kept in
`localStorage` inside try/catch. While speaking, the orb is set to speaking
and pulses `setLevel` on `boundary` events (speech output cannot be routed
into Web Audio). A stop control. Feature-checked: with no `speechSynthesis`
or no JS, the controls do not appear.

Done-test: `php tests/run.php --filter Voice` passes. VoiceTest (grep):
`voice.js` filters on `localService`, wraps `localStorage` in try/catch,
listens for `boundary` and `end`, calls the orb's `setState`, and holds no
`fetch` or `XMLHttpRequest`; the speak control is absent from the no-JS
HTML; `DomSinks` clean.

### 68. Chrome extension and Task Scheduler script
- Role: implementer. Risky path: yes (`chrome-extension/`). Depends: 63, 67, 72.
- `chrome-extension/manifest.json`
- `chrome-extension/newtab.html`
- `chrome-extension/newtab.js`
- `bin/register-tasks.ps1`
- `tests/ExtensionTest.php`
- `tests/TasksScriptTest.php`

MV3, overrides new tab, navigates to `http://127.0.0.1:8787/`. Tasks under
`\KC Dashboard\` (never a name starting `KC Finance`), `-LogonType S4U`,
`-MultipleInstances IgnoreNew`, `-WorkingDirectory` the repo, absolute
`c:\xampp\php\php.exe` and script paths. Worker every minute; fetch-news
every 15 minutes; collect-facts every 30 minutes; daily-read at 6 am;
collect-kc daily; collect-projects then write-vault every 5 minutes; brief
at 6:15 am. No mail task, no model at logon. `-WhatIf` supported.

Done-test: `php tests/run.php` with `--filter Extension` and `TasksScript`
pass. ExtensionTest: manifest is valid JSON, version 3, has
`chrome_url_overrides.newtab`, no `permissions`, `host_permissions` or
`content_scripts`; `newtab.js` targets exactly that URL. TasksScriptTest:
every `bin/*.php` job has a task with its stated interval, S4U, IgnoreNew
and working directory; no `-RunLevel Highest`; no task name starts `KC
Finance`; a `-WhatIf` run through ProcessRunner (powershell.exe added to the
allowlist for this test only) registers nothing.

### 69. Security audit
- Role: security-auditor. Risky path: yes. Depends: 59 to 68, 73 to 80.
- `src/Projects/`
- `src/Running/`
- `src/Vault/`
- `src/Memory/`
- `src/Http/`
- `src/Process/`
- `src/Fs/`
- `src/Claude/`
- `src/Projects/ProjectList.php`
- `src/Collectors/Finance.php`
- `src/Sys/Elevation.php`
- `bin/`
- `public/`
- `ops/apache-kc-dashboard.conf`
- `chrome-extension/`

Cross-card audit only; per-card audits run at the coordinator's gate.

Done-test: full `c:\xampp\php\php.exe tests/run.php` passes, `php
ops/http-check.php` exits 0, and the audit report names a test for each
spec Security bullet, each finance never-store item, and each accepted
review finding in revision 3's mapping, or files a card for any without one.

### Filed by card 69 (not briefed yet)
Detail and file:line in `.claude/work/69-audit/report.md`.
- **81. .credentials on port 80.** http-check probe plus a suite test that httpd.conf denies it.
- **82. One process starter.** Static test that proc_open and the exec family appear only in ProcessRunner.php across src/ and bin/; bind powershell, php and python to fixed argv inside ProcessRunner.
- **83. Finance evidence scrub.** Evidence is free text (Finance.php:83) and since card 79 lands in vault Briefings and the briefings table. Needs Rob's call on the contract, then a scrub and a test.
- **84. Read-only git in kc-finance.** AskHandler.php:87 git status gets GIT_OPTIONAL_LOCKS=0 and card 73's guards.
- **85. SSRF and token ACL.** Redirect-to-private-host test for CurlHttpClient (Codex 9); token file ACL test (Codex 12).
- **86. Test hygiene.** BriefingTest covers all 14 neutralised fields (loop_card); ApiGuardSweepTest points config/vault.php at a temp vault; TasksScriptTest shadows schtasks.exe and CIM.

## Waiting on Rob

### 08. Credentials in place
- Role: Rob. Depends: none. Needed before 67 runs for real; its code uses fakes.
- `D:\Dev\htdocs\.credentials\` (Rob's files; nobody reads the values)

A Bugsink token file and the GA4 service-account JSON path; revision 5 adds
the service account as a Viewer on every GA4 property and Search Console
site in `config/properties.php`. Rob gives file
names only. Done-test: `Test-Path` on each named file returns True.

### 28. Load the extension
- Role: Rob. Depends: 62, 68.

Rob loads it unpacked, opens a new tab, submits one question. Done-test: Rob
confirms the page shows with no elevation warning, and the Apache access log
for 8787 shows `POST /api/ask.php` with 200.

### 52. Register scheduled tasks
- Role: Rob. Depends: 68.

Rob reviews the `-WhatIf` output, then confirms the real run. Done-test:
`Get-ScheduledTask -TaskPath '\KC Dashboard\'` lists every task from card
68, and after `Start-ScheduledTask` on the worker a queued Ask Claude job
reaches `done`.

## Later (not built now)

- JARVIS round two, from `jarvis-research.md` sections 3 and 4, after Rob has
  used round one for a week: push-to-talk through whisper.cpp on 127.0.0.1
  (Rob places the zip and model); the local model as voice router
  (Qwen3.5-9B always on, Qwen 3.6 35B-A3B on demand when RAM allows, no
  gpt-oss); voice handoff cards to `claude -p` that Rob clicks to send
  (highest risk, needs a security-auditor pass); wake word in wall mode with
  sherpa-onnx; Piper or Kokoro voices; loop kanban and force-graph views in
  the dashboard; `gh` issue counts on tiles; Claude Code hooks feeding the
  running strip (needs Rob's global settings).
- Meta Ads (Marketing API insights, `ads_read`, a system user token Rob makes)
  and Buffer scheduled posts (its API has no engagement numbers).

- 61. LLM layer and Bonsai (ModelManager with `-np 1` and a canary per
  batch, load on demand). Only if Bonsai gets a job; both reviewers keep it
  off the critical path.
- 64. Impact tags. Bonsai may only add story-to-project candidates, never be
  the matcher of record. Needs 61.
- OSV-Scanner collector over lockfiles (needs an install, so it parks).
- Rule promotion (a pattern useful three times becomes a code predicate).
  Needs a week of feedback labels first.
- Moving the daily read to the API, if Rob lifts the no-API rule.
- 25 terminal button, 45 weekly note, 47 model update check, 48 health
  strip, 49 claude-loop status, 50 extras UI.
- ProcessRunner follow-ups (issue 3): grandchildren survive `taskkill /T`;
  whether a failed taskkill still blocks on `proc_close` is unchecked.
- Finance approval file (one-click reminder approval), if Rob wants it.

## Plan 6: KC Cockpit phase 1

Planned 2026-10-23 while Rob was AFK. Rob has not yet approved
`docs/AGENTOS.md`. These cards build on a feature branch and nothing merges
until card 87. Source: AGENTOS.md sections 7 and 10 (phase 1, 1.1 to 1.10).
Its two open questions are decided: the service stays on 127.0.0.1, and the
new tab stays the dashboard. The run goes through `claude-loop.cjs` once
agentos card 04 lands this repo's loop copy; revision 5's "not through
claude-loop" does not carry over (correction 13). Order: 87 (waits on Rob,
in parallel), then 88 to 104 as numbered, then 105 and 106.

Where this departs from the spec, and why:

- 7.2 puts `node --test cockpit/test` in `extra_checks`. `project-test.cjs`
  records mutation evidence only from runs of `test_command`, so every cockpit
  card would gate "mutation evidence: no" and bounce. Card 88 runs each node
  test file inside the PHP suite instead (`tests/CockpitNodeTest.php`).
- 7.2 makes all of `cockpit/` risky, which puts every card on Opus with an
  audit. Risky here: `cockpit/server.mjs`, `cockpit/lib/` except
  `cockpit/lib/views/`, and `cockpit/mcp.mjs`. That holds because the server
  guards every request by path before the route table is read, and a sweep
  test runs every route through the guard.
- Phase 1 starts `claude agents --json`, but `runner.mjs` waits for 2.1.
  Card 91 builds it now with a one-entry allowlist.
- `~/.claude/sessions/` also holds `.key` files (dashboard card 74). 7.4 does
  not say so; card 91 opens only `^\d+\.json$`.
- 1.1's `curl` test needs the live service on 8788. Tests start the server
  on an ephemeral loopback port.
- Vendoring cosmos.gl is an install, which parks per `project.md`; card 103
  waits on 87. Whether cosmos.gl runs under the spec's CSP (no `worker-src`)
  is unchecked; if not, it parks rather than loosening the CSP.
- 4.5's vault `[[links]]` need note contents, which 7.3 rules out. Left out.
- The duplicate-folder skip rests on one hashed pair. Card 89 hashes every
  overlap.
- 1.10's done-test registers a real task. After card 68's mutation reached
  `Register-ScheduledTask`, that run is Rob's (105).
- 1.8 is a whole screen with five parts: Normal, not Small.

### 87. Rob approves docs/AGENTOS.md (waiting on Rob)
- Role: Rob. Risky path: no. Depends: none. Blocks: merging any card below, 103, 105.
- `docs/AGENTOS.md`

Rob reads the spec, including the libraries in 5.5 and the departures listed
above. Building continues on the branch meanwhile.

Done-test: `Select-String -Path .claude/work/log.md -Pattern 'AGENTOS.md approved'`
finds the line the coordinator writes when Rob says so.

### 88. Cockpit service skeleton, guard and gate (Risky)
- Role: implementer. Risky path: yes (`cockpit/server.mjs`, `cockpit/lib/`, the rules in `.claude/project.md`). Depends: none.
- `cockpit/server.mjs`
- `cockpit/lib/guard.mjs`
- `cockpit/lib/config.mjs`
- `cockpit/lib/views/routes.mjs`
- `cockpit/config.json`
- `cockpit/public/index.html`
- `cockpit/test/guard.test.mjs`
- `cockpit/test/helpers/start-server.mjs`
- `tests/CockpitNodeTest.php`
- `config/executables.php`
- `.claude/project.md`

Plain Node 24, no npm dependencies. `server.mjs` binds `127.0.0.1` only, port
from `config.json` (8788) or `--port`, and the allowed Host list follows the
bound port. `guard.mjs` ports `src/Http/RequestGuard.php` rule for rule
(`checkRead`, `checkApiRead`, `checkFetchSite`, `checkWrite`; `hash_equals`
becomes `timingSafeEqual`). Before the route table is read, the server applies
`checkRead` to every request, `checkApiRead` to every `/api/` path and
`checkWrite` to every non-GET; `lib/views/routes.mjs` only maps paths to
handlers. The token comes from `data/token`; with no token file the service
stops and never creates one. Every response carries AGENTOS 7.7's CSP exactly.
`POST /api/ticket` returns a 30-second, one-use ticket. The service refuses a
Node major other than 24 and silences only the `node:sqlite` experimental
warning. `tests/CockpitNodeTest.php` registers one PHP test per
`cockpit/test/*.test.mjs`, named after the file, running `node --test <file>`
through ProcessRunner with `node.exe` absolute in `config/executables.php`; a
failure prints the tail of node's output. `project.md`: add the cockpit risky
paths from the note above, and to Never: binding the cockpit to anything but
127.0.0.1. `test_command` is unchanged and there is no `extra_checks`.

Done-test: `php tests/run.php --filter guard.test` passes and the full suite
stays green. guard.test.mjs, server on an ephemeral port: Host
`evil.test:<port>` gets 403; `Sec-Fetch-Site: cross-site` with mode `cors`
gets 403, with `navigate` on `/` gets 200; `/api/ticket` without
`X-KC-Token` gets 403; a POST with no Origin, or Origin `http://evil.test`,
gets 403; every response has the exact CSP string with
`frame-ancestors 'none'`; `server.address().address` is `127.0.0.1`; a
ticket works once and fails at 31 s on an injected clock; with no token file
in a temp root the server exits non-zero and creates no file; every route in
`routes.mjs` refuses a foreign Host and, under `/api/`, a missing token.
`Select-String .claude/project.md -Pattern 'cockpit/lib/'` matches.

### 89. The permanent index: transcripts (Risky)
- Role: implementer. Risky path: yes (`cockpit/lib/`). Depends: 88.
- `cockpit/lib/index/schema.sql`
- `cockpit/lib/index/db.mjs`
- `cockpit/lib/index/indexer.mjs`
- `cockpit/lib/index/transcripts.mjs`
- `cockpit/lib/scrub.mjs`
- `cockpit/lib/never-show.mjs`
- `cockpit/bin/index.mjs`
- `cockpit/test/index.test.mjs`
- `cockpit/test/fixtures/claude-projects/`

Port the spike (`.claude/work/agentos/data-spike.md` has the schema and
logic) to `node:sqlite`, writing `data/agentos.sqlite` in WAL mode. Sources:
`~/.claude/projects/**/*.jsonl` plus each session's `subagents/*.jsonl` and
`*.meta.json`, root from config so tests use fixtures. A byte offset per file,
complete lines only, rows keyed on source ids. A session file under both
`c--xampp-htdocs-*` and `d--Dev-htdocs-*` counts once only when the shorter
file's hash matches the longer's prefix; otherwise both are read. Tables: the
spike's `projects`, `files`, `sessions`, `tool_calls` (plus `ended` and `ms`
from the tool result), `agent_messages`, `file_access`, and `index_health`.
Never stored: command text, tool output, file contents. Briefs and reports
pass `scrub.mjs` (it loads `C:\Users\roban\.claude\hooks\secret-patterns.cjs`
from a path in config and stores the field empty if that fails), then are cut
to 500 characters. `never-show.mjs` is the one list (`.credentials`, `.env*`,
`*.key`, `*.pem`, and what credential-read-blocker refuses) and drops matching
paths. Unknown records count in `unknown_records`, with `parser_version` per
pass. Fake keys in tests are built at run time, so no key-shaped literal is
written to a file.

Done-test: `php tests/run.php --filter index.test` passes. On fixtures: a
session with two subagents and one nested agent resolves all three parent
links; a half-written last line is not indexed until it ends; a second pass
over unchanged fixtures reads 0 bytes; a true-prefix duplicate pair is
indexed once and a same-named non-prefix pair twice; after a checkpoint, a
fake key planted in a brief, a Bash command and a tool result is absent from
the database file's bytes; a Read of `.credentials\x` gives no `file_access`
row; one unknown record type raises `unknown_records` by 1; a tool result
sets `ms`. Then, once, `node cockpit/bin/index.mjs --stats` on the real
history prints agent-to-parent links at 99% or more, and a second run
finishes under 5 s.

### 90. The index: loop runs, costs and usage readings (Risky)
- Role: implementer. Risky path: yes (`cockpit/lib/`). Depends: 89.
- `cockpit/lib/projects.mjs`
- `cockpit/lib/index/loops.mjs`
- `cockpit/lib/index/schema.sql`
- `cockpit/lib/index/indexer.mjs`
- `cockpit/test/loops-index.test.mjs`
- `cockpit/test/fixtures/htdocs/`

`projects.mjs` lists in-scope projects fresh each call:
`D:\Dev\htdocs\*\.claude\project.md` plus `C:\Users\roban\kc-finance` from
config, keys `^[A-Za-z0-9._-]+$`, realpath checked under the allowed root.
`loops.mjs` reads each project's `.claude/work/loop/*.jsonl` and
`status.json` into `loop_passes`, `loop_runs` (launcher from `launched_by` in
`status.json` as `status`, else the ten-minute heuristic as `heuristic`) and
`usage_readings` from `rate_limit_event` records. Result text is never
stored. A session's cost is its largest `total_cost_usd`, never the sum.

Done-test: `php tests/run.php --filter loops-index` passes. Fixtures: run,
card and pass come from file names (`-gate1`, `-push`, `-audit1`,
`-handoff`); resumed passes at 0.48, 0.54 and 0.58 cost the session 0.58; a
`claude-loop` Bash call 4 minutes before a run links it as `heuristic` and
one 11 minutes before does not; `launched_by` in `status.json` gives
`status`; a `rate_limit_event` gives one `usage_readings` row with both
windows and resets; a marker in a `result` record is absent from the
database bytes; a junction pointing outside the root is not listed.

### 91. Live registry and the one process door (Risky)
- Role: implementer. Risky path: yes (`cockpit/lib/`). Depends: 90.
- `cockpit/lib/runner.mjs`
- `cockpit/lib/live.mjs`
- `cockpit/config.json`
- `cockpit/test/live.test.mjs`
- `cockpit/test/fixtures/live/`

`runner.mjs` is the only module that starts a child process: an absolute
executable from an allowlist, an argument array, `shell: false`, `.bat` and
`.cmd` refused. Phase 1's allowlist is one entry: `claude.exe` (absolute path
in `config.json`, the same as `config/executables.php`) with exactly
`agents --json`. `live.mjs` polls it every 15 s, parses both shapes (`status`
idle or busy; `state` and `waitingFor`) and keeps the previous list on bad
output. It watches `~/.claude/sessions/` only to trigger a poll, and opens
only names matching `^\d+\.json$`. Loops per project: `lock` with a PID alive
by `process.kill(pid, 0)`, `STOP`, `status.json`, giving running, stopped,
stale or none. An agent is running or done by whether its report has arrived
and its file's last write.

Done-test: `php tests/run.php --filter live.test` passes. With a stub
`claude` (a node script named in the test config): both output shapes give
the same session list; garbage output keeps the previous list and does not
throw; the runner refuses an executable off the allowlist, other arguments
and a `.cmd`, and records `shell: false`; a `.key` file in the fixture
sessions folder is never opened (a read spy); a lock holding the test's own
PID is running, a dead PID stale, lock plus `STOP` stopped.

### 92. The event stream (Risky)
- Role: implementer. Risky path: yes (`cockpit/server.mjs`, `cockpit/lib/`). Depends: 91.
- `cockpit/lib/events.mjs`
- `cockpit/lib/index/watch.mjs`
- `cockpit/lib/index/schema.sql`
- `cockpit/server.mjs`
- `cockpit/test/events.test.mjs`

An `events` table keeping 24 hours. `fs.watch` (recursive, debounced 1 s per
file) on the projects folder and each loop folder, plus a 30-second sweep,
runs the indexer and emits `session`, `tool`, `agent`, `message`, `loop`,
`usage` and `index` events with rising ids. `GET /events` is server-sent
events, opened with a ticket from card 88; `Last-Event-ID` replays from the
table.

Done-test: `php tests/run.php --filter events.test` passes: a line appended
to a fixture transcript reaches a connected client as a `tool` event within
2 s; reconnecting with `Last-Event-ID: 5` gets 6 onward once each; no ticket
or a spent ticket gets 403; events older than 24 h are pruned on an injected
clock; 200 lines appended at once arrive as 200 events in order.

### 93. Shared colour tokens in the dashboard (Small)
- Role: implementer. Risky path: no. Depends: none.
- `public/assets/tokens.css`
- `public/assets/hud.css`
- `public/assets/app.css`
- `public/assets/wall.css`
- `src/View.php`
- `public/wall.php`
- `tests/HudTest.php`

The dashboard's custom properties move into `tokens.css`, with the names in
AGENTOS 5.3, so the cockpit loads the same file. No visible change.

Done-test: `php tests/run.php --filter Hud` passes, and the full suite and
`php ops/http-check.php` stay green. HudTest adds: `tokens.css` defines
every property named in AGENTOS 5.3; no other `public/assets/*.css` defines
one of them; the page and wall output link `tokens.css` before `hud.css`.

### 94. Page checks: slop, motion and DOM sinks (Normal)
- Role: implementer. Risky path: no. Depends: 88.
- `cockpit/bin/check-slop.mjs`
- `cockpit/bin/check-motion.mjs`
- `cockpit/test/checks.test.mjs`
- `cockpit/test/fixtures/pages/`
- `tests/CockpitDomSinksTest.php`

Both scripts scan `cockpit/public/` (skipping `vendor/`) and
`cockpit/lib/views/`, and exit 1 naming file and line. check-slop enforces
what AGENTOS 5.6 says a script can: no uppercase text with `letter-spacing`
of 0.1em or more outside a panel-title selector; no beige or cream (hue 20 to
60, lightness above 85%); no comma in an `h1` to `h3` or `<title>`; no spot
holding two buttons side by side. check-motion: every `@keyframes` is off
under `prefers-reduced-motion`, and every module calling
`requestAnimationFrame` also handles `visibilitychange` and reduced motion.
`tests/Support/DomSinks.php` runs over `cockpit/public/`. checks.test runs
both scripts on the real folders, so every later page card is gated by them.

Done-test: `php tests/run.php --filter checks.test` and
`--filter CockpitDomSinks` pass. Fixtures: an eyebrow rule, `#f3efe7`, an
`<h2>` reading "Fast, done" and two adjacent buttons each make check-slop
exit 1 naming the file; a keyframe with no reduced-motion override makes
check-motion exit 1; a fixture `.mjs` using `innerHTML` fails DomSinks; the
real `cockpit/public/` passes all three.

### 95. Cockpit shell (Normal)
- Role: implementer. Risky path: no. Depends: 92, 93, 94.
- `cockpit/lib/views/shell.mjs`
- `cockpit/lib/views/routes.mjs`
- `cockpit/public/index.html`
- `cockpit/public/cockpit.css`
- `cockpit/public/app.mjs`
- `cockpit/public/router.mjs`
- `cockpit/public/palette.mjs`
- `cockpit/test/shell.test.mjs`

The server renders the shell and each screen's current values as HTML on
first load; JS then takes over. Rail, top bar with clock and usage pill,
breadcrumb, hash routes (`#/overview`, `#/project/<key>`, `#/session/<id>`),
Esc up a level, `G` then a letter, a Ctrl+K palette over `/api/search`
(projects, sessions, cards). Skeletons in `--glass` with a 1.4 s shimmer,
still under reduced motion. Mobile first with `min-width` only: the phone
stack at base, rail and grid at 1024px, the wall at 1600px. Loads
`/tokens.css`, served from `public/assets/tokens.css`. The token goes into
one `<meta>` tag. Drill-downs use View Transitions where present.

Done-test: `php tests/run.php --filter shell.test` passes and checks.test
stays green. shell.test: `GET /` holds the rail, top bar, breadcrumb and each
screen's placeholder by id; the token occurs exactly once, in the `meta`
tag; `cockpit.css` has no `max-width` media query; `/api/search` is in the
guard sweep. Then, with `node cockpit/server.mjs` running,
`node C:\Users\roban\.claude\skills\mobile-first-responsive\scripts\responsive-sweep.cjs http://127.0.0.1:8788/`
exits 0.

### 96. Overview panels (Normal)
- Role: implementer. Risky path: no. Depends: 95.
- `cockpit/lib/views/overview.mjs`
- `cockpit/lib/views/routes.mjs`
- `cockpit/public/overview.mjs`
- `cockpit/public/feed.mjs`
- `cockpit/public/cockpit.css`
- `cockpit/test/overview.test.mjs`

AGENTOS 4.1 without the core: Needs you, Live feed (pauses when scrolled up,
shows "N new", keeps 700 rows), workforce strip with tool-call sparklines,
timeline, usage (reading age shown, muted over 30 minutes), memory insights
(top five files today), index health (red when unknown records rise). A
panel with no source says "not connected".

Done-test: `php tests/run.php --filter overview.test` passes: first-paint
HTML holds all seven panels by id, each with its fixture value, or "not
connected" when its source is empty; a usage reading 47 minutes old renders
"47 min ago" with the muted class; a parked loop card and a session waiting
for input both appear in Needs you; the feed buffer keeps the newest 700 of
750; a feed title holding `<img src=x onerror=alert(1)>` arrives as text.

### 97. The core: hierarchy globe (Normal)
- Role: implementer. Risky path: no. Depends: 96.
- `cockpit/public/hierarchy.mjs`
- `cockpit/public/core.mjs`
- `cockpit/public/cockpit.css`
- `cockpit/test/core.test.mjs`

Grown from the dashboard's `public/assets/orb.js`, whose copy stays as is.
`hierarchy.mjs` is pure: workforce data in, nodes out (a "PM not running"
slot at the core, managers on the inner ring in their kind's colour, agents
around their manager, nested agents around their parent, idle projects on
the outer ring). `core.mjs` draws it on canvas 2D to the AGENTOS 5.4 motion
table: events batched once per animation frame, at most one pulse per link
per 400 ms, no frames on a hidden tab, one still frame under reduced motion,
pixel ratio capped at 2, a static CSS ring without JS.

Done-test: `php tests/run.php --filter core.test` passes and check-motion
exits 0: from a fixture agents list every live session lands on the inner
ring in its kind's colour token, a project idle over 24 hours on the outer
ring, a depth-2 agent under its parent agent; the pulse limiter drops a
second pulse on one link inside 400 ms on an injected clock; with
`devicePixelRatio` 3 the canvas scale is 2.

### 98. Workforce screen (Normal)
- Role: implementer. Risky path: no. Depends: 97.
- `cockpit/lib/views/workforce.mjs`
- `cockpit/lib/views/routes.mjs`
- `cockpit/public/workforce.mjs`
- `cockpit/public/cockpit.css`
- `cockpit/test/workforce.test.mjs`

AGENTOS 4.4: the orbit view (the core at full size with labels); the tree
grouped Needs you, Working, Ready for review, Done, with arrow keys and
Enter; the Messages tab over `agent_messages` (the indexed 500 characters, a
row linking to the session screen for full text), filtered by project and
agent type; windows now, today and 7 days. The tree is the no-JS and phone
rendering.

Done-test: `php tests/run.php --filter workforce.test` passes: a fixture
loop run nests as run, card, session, agents, with gate and push passes on
the card session's timeline rather than as managers; the no-JS tree's node
ids equal `hierarchy.mjs`'s for the same fixture; filtering Messages by
project returns only that project's rows, newest first; a brief holding
`<img src=x onerror=alert(1)>` is escaped in the HTML.

### 99. Session screen: live transcript (Risky)
- Role: implementer. Risky path: yes (`cockpit/lib/`). Depends: 95.
- `cockpit/lib/transcript.mjs`
- `cockpit/lib/views/session.mjs`
- `cockpit/lib/views/routes.mjs`
- `cockpit/public/session.mjs`
- `cockpit/public/cockpit.css`
- `cockpit/test/session.test.mjs`

`transcript.mjs` reads a session's `.jsonl` from disk for full text. The
session is found by id in the index (the client never sends a path), its
file's realpath must sit under the projects root, and every string passes
`scrub.mjs` and `never-show.mjs` before it leaves. Prompts and replies as
blocks; tool calls as one-line rows expanding to the scrubbed input; an
`Agent` row expanding into the child's transcript, brief on top and report
below. New lines arrive over `/events`. The page writes with `textContent`
only.

Done-test: `php tests/run.php --filter session.test` passes: an id not in
the index, and an id holding `..` or a slash, get 404; a fake key built at
run time in a prompt, a tool input and an agent report appears in no
response body; a Read of `.credentials\x` shows the row with the path
withheld; an `Agent` row carries the child transcript; a line appended to
the open session reaches its client within 2 s.

### 100. Session screen: trace and files touched (Normal)
- Role: implementer. Risky path: no. Depends: 99.
- `cockpit/lib/views/trace.mjs`
- `cockpit/lib/views/routes.mjs`
- `cockpit/public/trace.mjs`
- `cockpit/public/session.mjs`
- `cockpit/test/trace.test.mjs`

From the index: a waterfall per card (pass, sessions, subagents, tool calls,
timed from `ms`) and the files touched with op and time. Shell calls show as
"shell, paths unknown".

Done-test: `php tests/run.php --filter trace.test` passes: a fixture card
gives the four nested levels in that order with the durations from its tool
results; a Bash call shows "shell, paths unknown" and no path; files touched
lists each path once per op, newest first.

### 101. Project screen (Normal)
- Role: implementer. Risky path: no. Depends: 95.
- `cockpit/lib/views/project.mjs`
- `cockpit/lib/views/routes.mjs`
- `cockpit/public/project.mjs`
- `cockpit/public/cockpit.css`
- `cockpit/test/project.test.mjs`

AGENTOS 4.2 without the rules row (phase 2). The project comes from
`projects.mjs` by key. Header from the dashboard database's `projects` table
(card 73), read-only, never a git call. Managers and agents as a tree; the
loop's current card and last ten cards as a waterfall, cost per card from
the max per session; the board as the project's `checklist.json` entries not
yet passing; memory files with access counts.

Done-test: `php tests/run.php --filter project.test` passes: an unknown key
or one holding `..` gets 404; three resumed passes cost the max, not the
sum; the board lists only `passes: false` entries; the memory list holds no
never-show path; the fixture project folder's file hashes are unchanged
after a render.

### 102. Memory galaxy: data and the list (Normal)
- Role: implementer. Risky path: no. Depends: 95.
- `cockpit/lib/views/graph.mjs`
- `cockpit/lib/views/routes.mjs`
- `cockpit/public/memory.mjs`
- `cockpit/public/cockpit.css`
- `cockpit/test/graph.test.mjs`

Nodes and edges from `file_access` per AGENTOS 4.5: files by kind, projects,
and optionally sessions and agents; edges weighted by count and op; windows
of 24 hours, 7 days, 30 days and all. Persona files are path, kind and
counts only. `never-show.mjs` filters again here. A file opens to who
touched it, when and how. No vault `[[links]]`. This card draws only the
fallback: the 50 hottest files as a list, which is also the no-JS view.

Done-test: `php tests/run.php --filter graph.test` passes: never-show paths
planted in fixture `file_access` (`.env`, `a.key`, `.credentials\b`) yield
no node; each persona node's keys are exactly path, kind and counts; the
four windows count correctly on an injected clock; the no-JS HTML lists the
50 hottest files by count.

### 103. Memory galaxy: drawing (Normal, waits on 87)
- Role: implementer. Risky path: no. Depends: 87, 102.
- `cockpit/public/vendor/cosmos-graph-3.5.1.min.js`
- `cockpit/public/vendor/VENDOR.txt`
- `cockpit/bin/check-vendor.mjs`
- `cockpit/public/galaxy.mjs`
- `cockpit/test/vendor.test.mjs`

Vendor `@cosmos.gl/graph` 3.5.1 (`dist/index.min.js`, MIT) with version,
licence, source URL and sha256 in `VENDOR.txt`. The galaxy per 4.5 and
`7-memory-galaxy.png`: centre and arms layout, brightness by log access
count, comets fading over 30 s, the time scrubber with 60x replay, a still
star field as skeleton, layout frozen after 3 s, one laid-out frame under
reduced motion. On at 1024px with WebGL2; the list stays otherwise. If it
needs `worker-src`, `blob:` or `unsafe-eval`, park; never loosen the CSP.

Done-test: `php tests/run.php --filter vendor.test` passes and check-motion
exits 0: check-vendor exits 0 on the vendored file and 1 after a one-byte
change to a temp copy; `VENDOR.txt` names 3.5.1, MIT and the source URL; no
file under `cockpit/public/` imports from an `http` URL.

### 104. Single instance and the logon task script (Risky)
- Role: implementer. Risky path: yes (`cockpit/server.mjs`, `cockpit/lib/`). Depends: 88.
- `cockpit/lib/instance.mjs`
- `cockpit/server.mjs`
- `bin/register-tasks.ps1`
- `tests/TasksScriptTest.php`
- `cockpit/test/instance.test.mjs`

A PID file in `data/` keeps one service running; a PID file whose process is
dead is taken over. `bin/register-tasks.ps1` gains `\KC Dashboard\Cockpit`:
at logon, as Rob, restart on failure (three times, a minute apart),
`-MultipleInstances IgnoreNew`, absolute `node.exe` and `cockpit/server.mjs`,
plus a Start menu shortcut running `chrome.exe --app=http://127.0.0.1:8788/`,
both honouring `-WhatIf`. This card registers nothing.

Done-test: `php tests/run.php --filter instance.test` and
`--filter TasksScript` pass: a second start against a live PID file exits
non-zero printing "already running"; a dead PID file is taken over; the
`-WhatIf` run lists the Cockpit task with its logon trigger, restart
settings, IgnoreNew and absolute paths, registers nothing and creates no
shortcut, with card 68's stand-in checks still holding.

### 105. Rob registers the cockpit task (waiting on Rob)
- Role: Rob. Depends: 87, 104.

Rob reviews the `-WhatIf` output and runs it for real.

Done-test: `powershell -c "(Get-ScheduledTask -TaskPath '\KC Dashboard\' -TaskName Cockpit).State"`
prints `Ready` or `Running`, and after signing out and in,
`http://127.0.0.1:8788/` answers.

### 106. Cockpit phase 1 security audit
- Role: security-auditor. Risky path: yes. Depends: 88 to 104.
- `cockpit/server.mjs`
- `cockpit/lib/`
- `cockpit/public/`
- `cockpit/bin/`
- `tests/CockpitNodeTest.php`
- `bin/register-tasks.ps1`
- `.claude/project.md`

Over the feature branch's whole diff, before it merges. Per-card audits still
run at the gate.

Done-test: the full suite through `R` passes, and
`.claude/work/106-audit/report.md` names a test for each AGENTOS 7.7 bullet
phase 1 builds (bind, every request, API reads, writes, token, ticket, CSP,
ids, untrusted text, secrets), or files a card for any without one.
