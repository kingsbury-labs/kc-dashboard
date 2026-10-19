# Plan: KC Dashboard (whole spec), revision 4

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
for HN points, through card 71's `HttpClient`.

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

### 67. KC numbers
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

Done-test: `php tests/run.php` with `--filter CollectGa`, `Numbers` and
`NumbersApi` each pass. CollectGaTest runs `python.exe bin/collect_ga.py
--from-fixture ...` through ProcessRunner and asserts the JSON keys, and by
grep that the script holds `analytics.readonly` and `webmasters.readonly`,
no `buffer`, no `--index`, no `open(` in write mode. NumbersTest: fixture
outputs stored; one failing source stores its error and the others still
store; the config holds the Bugsink token path, not a value.
NumbersApiTest: latest values returned, foreign Host rejected, `DomSinks`
clean.

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
collect-kc daily. No mail task, no model at logon. `-WhatIf` supported.

Done-test: `php tests/run.php` with `--filter Extension` and `TasksScript`
pass. ExtensionTest: manifest is valid JSON, version 3, has
`chrome_url_overrides.newtab`, no `permissions`, `host_permissions` or
`content_scripts`; `newtab.js` targets exactly that URL. TasksScriptTest:
every `bin/*.php` job has a task with its stated interval, S4U, IgnoreNew
and working directory; no `-RunLevel Highest`; no task name starts `KC
Finance`; a `-WhatIf` run through ProcessRunner (powershell.exe added to the
allowlist for this test only) registers nothing.

### 69. Security audit
- Role: security-auditor. Risky path: yes. Depends: 59 to 68.
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

## Waiting on Rob

### 08. Credentials in place
- Role: Rob. Depends: none. Needed before 67 runs for real; its code uses fakes.
- `D:\Dev\htdocs\.credentials\` (Rob's files; nobody reads the values)

A Bugsink token file and the GA4 service-account JSON path. Rob gives file
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
