# Plan: KC Dashboard (whole spec), revision 3

Source: `docs/SPEC.md`. Rules: `.claude/project.md`, `CLAUDE.md`. Reviews:
`.claude/work/reviews/codex.md`, `.claude/work/reviews/fable.md`.

Revision 3 (2026-10-12, Rob's direction): single-user local dashboard, each
card costs about five minutes of loop overhead, so what is left is collapsed
into feature-sized cards numbered from 60. Old cards 16 to 53 are retired;
each lives on inside the new card named below, or under Later. Every card
leaves `c:\xampp\php\php.exe tests/run.php` green. Code cards use fakes and do
not wait on Rob unless they say so.

## Review outcome

Accepted (source, number: old card that absorbed it; new home after the arrow):

- Codex 1, REMOTE_ADDR part only: 10 (guard refuses non-loopback REMOTE_ADDR); its integration-test ask: 06. Both done.
- Codex 3, Fable 10, Codex 24 (dashboard-collect.py drags in Buffer, gh, cache writes, prose output, URL Inspection instead of metrics): 43, 44. Now 67.
- Codex 4, Fable 5 (injection reaching `claude -p`, no output schema): 20, 21, 22, 23, 37, 41, 45. Now 60, 61, 64, 66; 45 in Later.
- Codex 5, Fable 8 (junction TOCTOU, `is_link` false for junctions): 14, 15, 16. 14 and 15 done; 16 now 60.
- Codex 6, Fable 3, Fable 18 (one hardened ProcessRunner, `.bat`/`.cmd` refusal, tree kill): 15, 25. 15 done; 25 in Later.
- Codex 7, Fable 13, Fable 14 (IMAP TLS, quoting, credential format, `mail.sembr.co` cert): 08, 39, 41. 08 waiting on Rob; now 65, 66.
- Codex 8 (mail key and MIME): 40, 41. Now 65, 66.
- Codex 9, scheme, size, time and link parts: 32, 34. Now 63.
- Codex 10, Codex 13, Fable 1, Fable 16 (own origin, only `public/` reachable, real HTTP checks): 06, 07, 10, 27. 06 and 10 done; 07 waiting on Rob; 27 now 68.
- Codex 11, Fable 17 (exact Host/Origin, read `HTTP_HOST`): 10. Done.
- Codex 12, atomic token file, errors and logs: 05, 10, 11. Done.
- Codex 14 (no worker registry): 13. Done.
- Codex 15, Fable 22 (fallback on the current call, cold Ollama load): 18, 23. Now 61.
- Codex 16, Fable 4 (nothing starts llama-server, VRAM): 09, 18, 19, 48. 09 done; 18, 19 now 61; 48 in Later.
- Codex 17, Fable 21 (clients tests, two tables): 29, 30. Now 62.
- Codex 18, Fable 28 (profile schema, lockfile versions): 35. Now 64.
- Codex 19 (impact tags never shown): 38. Now 64.
- Codex 20, Fable 2 (bodies never stored): 41, 42. Now 66.
- Codex 21 (no-store, CSP, nosniff): 11. Done.
- Codex 22, Fable 11 (update flag has no table or display): 47, 48. Later.
- Codex 23, Fable 12 (extras UI, table, loop scan): 49, 50. Later.
- Codex 25, Fable 6 (task triggers, S4U, overlap): 51, 52. Now 68; 52 waiting on Rob.
- Codex 26, Fable 23 (card 04 and model card stale): 02, 03 marked done.
- Fable 7, Fable 29 (stack traces with arguments, testable responses): 06, 11. Done.
- Fable 9 (database is locked): 01. Done.
- Fable 15 (no HN points source): 31. Now 63.
- Fable 19 (`innerHTML` grep too narrow): 26. Now 62.
- Fable 20 (single-cluster pass, injected project names): 33, 37. Now 63, 64.
- Fable 24 (untestable boundary test): 20. Now 60.
- Fable 25 (no logger): 05. Done.
- Fable 26 (`nvidia-smi` per page load): 19, 48. Now 61 (CLI only); 48 in Later.
- Fable 27 (elevated Apache): 28, 48. The elevation warning moves from 48 into 62, so it is not lost with 48.

Rejected:

- Codex 1, remainder, and Codex 2: already fixed. Apache listens on 127.0.0.1:80 and [::1]:80 only (2026-10-12) and `httpd.conf` denies `.credentials`.
- Codex 9, private-address blocking after redirects: feeds are a fixed list Rob edits and responses only become stored text, so there is nothing to exfiltrate to.
- Codex 12, Windows ACLs on `data/token`: single-user PC; another local account is outside the threat model.
- Codex 27: the coordinator's gate already demands mutation evidence per card.
- Codex 28: the gate already runs a security-auditor pass per risky card; card 69 stays as the final cross-card audit.

## Flags

- Until card 07 installs the vhost, port 80 serves the whole project folder, so any website can make Apache run `bin/worker.php` or `tests/run.php`. The guard stops `public/api/*`, but not CLI scripts. Card 60 makes every `bin/*.php` and `tests/run.php` refuse a non-CLI SAPI, with a test that scans them all. 07 should still happen before card 60 lands.
- `Registry` builds handlers with `new $class()`, so a handler cannot be given fakes. Card 60 lets an entry be a class name or a factory closure.
- Card 09 is done by Rob's call (keep `gpt-oss:20b`), but its budget was never measured on a CPU-offload run. Card 61 adds `bin/model.php bench ollama`, which prints tokens per second; until someone runs it, the Ollama budget in `config/llm.php` is a guess.
- Card 62 does not need card 61. Running 62 second instead of third gets a usable page one card sooner.
- Card 28's done-test named the terminal button (25) and health strip (48), both now Later. Those clauses are removed.
- Card 67 ships without the weekly note (45, Later), so the numbers panel shows numbers only.
- `.claude/project.md` now lists `src/Process/`, `src/Fs/` and `ops/` as risky; the revision 2 flag about that is closed.

## Done

- 01. Test runner and SQLite bootstrap (done).
- 02. Decisions: Apache user, Python, claude.exe (done).
- 03. Local models on disk (done).
- 04. Spec update for the review decisions (done).
- 05. Logger with redaction (done).
- 06. Apache vhost snippet and HTTP check script (done).
- 09. Fallback model decision (done 2026-10-12: Rob keeps `gpt-oss:20b`).
- 10. Request guard and install token (done).
- 11. Responses, error handling and front controller (done).
- 12. Job queue (done).
- 13. Worker and job registry (done).
- 13a. Test runner fails on a broken test file (done).
- 14. Safe directory check (junctions) (done).
- 15. ProcessRunner (done).

## Cards

### 60. Ask Claude core
- Role: implementer. Risky path: yes (`src/Claude/`, `public/api/`, `src/Fs/`). Depends: done cards only. Absorbs 16, 20, 21, 22, 24.
- `src/Projects/ProjectList.php`
- `src/Fs/SafeDir.php`
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
- `tests/PromptTest.php`
- `tests/StructuredOutputTest.php`
- `tests/ClaudeRunnerTest.php`
- `tests/AskApiTest.php`
- `tests/WorkerTest.php`
- `tests/BinCliTest.php`

ProjectList: folders directly under a root (default `c:\xampp\htdocs`), fresh
each call, names `^[A-Za-z0-9._-]+$` only (`wt.exe` splits on `;`), dot
folders skipped by a rule that moves into `SafeDir`, anything `SafeDir` rejects
skipped; a name resolves only if it is in the listing. Prompt: fixed system
instruction that the data block is quoted evidence, never instructions;
boundary regenerated while the payload contains it; generator injectable.
StructuredOutput: required fields, enums, length caps, unknown fields dropped.
ClaudeRunner: absolute `claude.exe` from `config/executables.php`, `-p
--output-format json`, prompt on stdin, through ProcessRunner; untrusted mode
(any `Prompt`-built prompt) runs in a fresh empty temp folder with
`--disallowedTools Bash,Edit,Write,MultiEdit,NotebookEdit,WebFetch,WebSearch`.
ask.php: `checkWrite()`, project via ProjectList, blank = home folder (a
runner rooted at the home folder's parent, so the same recheck applies),
enqueue. answers.php: `checkRead()`, JSON. AskHandler runs ClaudeRunner with
the SafeDir recheck at launch, keeps 20 answers, registered as `ask` through a
factory so tests inject `FakeProcessRunner`. Worker calls `recoverStale`
before claiming. Every `bin/*.php` and `tests/run.php` exit non-zero when
`PHP_SAPI !== 'cli'`.

Done-test: `php tests/run.php` with `--filter ProjectList`, `SafeDir`,
`Prompt`, `StructuredOutput`, `ClaudeRunner`, `AskApi`, `Worker` and `BinCli`
each pass. ProjectListTest: a temp root with `alpha/`, `.credentials/`,
`.hidden/`, `semi;colon/`, a file and a real junction lists `['alpha']`; `..`,
`alpha/..`, `alpha\sub`, `C:\Windows`, `.credentials`, the junction and an
empty name resolve to null. SafeDirTest: the volume-GUID junction test is
rebuilt so deleting the stat/lstat inode check fails it (log, card 14), and a
dot folder is rejected by SafeDir itself. PromptTest: with a seeded generator
whose first three boundaries are planted in the payload, the final boundary is
absent from the payload and wraps it twice, and the instruction precedes the
block. StructuredOutputTest: extra fields dropped, out-of-enum value or
invalid JSON gives null, over-length reason truncated. ClaudeRunnerTest: argv
has `-p` and `--output-format json`, never `--dangerously-skip-permissions` or
`--permission-mode`; the prompt is in no argv item; untrusted mode sets an
empty temp cwd and the disallowed list; timeout returns a timeout error; the
JSON `result` field is parsed. AskApiTest: each card 10 rejection and an
unknown project create zero jobs, a valid request creates one, the handler
stores the fake answer, a 21st drops the oldest, `Registry` maps `ask`, a
project swapped for a junction after enqueue is refused at launch,
answers.php refuses a foreign Host. WorkerTest: a claim older than the
timeout is recovered and run. BinCliTest: every `bin/*.php` and
`tests/run.php`, run through `php-cgi.exe`, exits non-zero and runs nothing.

### 61. LLM layer
- Role: implementer. Risky path: no (launches only through ProcessRunner; covered by 69). Depends: 60. Absorbs 17, 18, 19, 23.
- `src/Net/HttpClient.php`
- `src/Net/CurlHttpClient.php`
- `src/Llm/ChatClient.php`
- `src/Llm/OpenAiClient.php`
- `src/Llm/ModelManager.php`
- `src/Llm/Health.php`
- `src/Llm/GpuStats.php`
- `src/Llm/Router.php`
- `bin/model.php`
- `config/llm.php`
- `src/migrations/004_llm_health.sql`
- `tests/Fakes/FakeHttpClient.php`
- `tests/OpenAiClientTest.php`
- `tests/ModelManagerTest.php`
- `tests/HealthTest.php`
- `tests/RouterTest.php`

`config/llm.php`: `bonsai` at `http://127.0.0.1:8081/v1`, `ollama` naming
`gpt-oss:20b`, `claude`, each with a budget. Requests send
`chat_template_kwargs: {enable_thinking: false}` and a `json_schema`
`response_format` when a schema is given. CurlHttpClient has connect and total
timeouts and a response size cap. ModelManager keeps one model in VRAM:
`ensure('bonsai')` unloads Ollama (`keep_alive: 0`, confirmed by `/api/ps`
empty), starts `llama-server.exe -m <gguf> -ngl 99 -fa on -c 8192
--cache-type-k q8_0 --cache-type-v q8_0 --jinja --host 127.0.0.1 --port 8081`,
records the pid in `data/`, waits for `/health` 200; `ensure('ollama')` kills
llama-server by pid first, then warms with a 1-token call outside any budget.
Runs from the worker and `bin/model.php` only, never Apache. `bin/model.php
bench ollama` prints tokens per second. Health records per call (backend,
ok/down/slow, latency, tokens per second, VRAM from `nvidia-smi` through
ProcessRunner, CLI only) and per probe. Router: `bulk` = bonsai then ollama,
`judgement` = claude through `ClaudeRunner`; `ensure` first, switch time
outside the budget; hard timeout = budget; on `BackendDown` or timeout the
same call moves on; down backends skipped for a cooldown; one Health row per
attempt.

Done-test: `php tests/run.php` with `--filter OpenAiClient`, `ModelManager`,
`Health` and `Router` each pass. OpenAiClientTest: POST path, model,
`enable_thinking` false, schema in `response_format`, parsed content and
`usage`, tokens per second from fake timing, `BackendDown` on connection
error, HTTP 5xx and timeout. ModelManagerTest: exact argv above with absolute
paths, Ollama unload precedes the llama-server start, switching to Ollama
kills the recorded pid first, `ensure` fails cleanly when `/health` never
answers, a second `ensure` of the live model starts nothing. HealthTest:
latest record per backend, rows over 7 days pruned, a probe older than 10
minutes reads `unknown`, VRAM parsed from fixture CSV, missing `nvidia-smi`
gives null, and `GpuStats` refuses when `PHP_SAPI !== 'cli'`. RouterTest, fake
clock: a down first backend and an over-budget first backend both answer the
current call from the second; all down throws `NoBackendAvailable`; the
`bulk` chain holds no `claude`; one Health row per attempt.

### 62. Page UI shell: search, Ask Claude, answers, clients
- Role: implementer. Risky path: yes (`public/api/`, `src/Http/`). Depends: 60. Absorbs 26, 29, 30, the elevation half of 48, and card 11's follow-ups from the log.
- Also: `php ops/http-check.php` (run 2026-10-12 against the live 8787 vhost)
  fails one check, `/ GET: body does not contain the token`: the token appears
  on `/` somewhere other than its single `<meta>` tag. The done-test for this
  card includes `php ops/http-check.php` exiting 0.
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

ES-module JS sends `X-KC-Token` on POST, polls answers, writes only with
`textContent`. No terminal button (25 is Later). Clients: only the table under
`## Active Projects`, markdown emphasis, arrows and emoji stripped, due date
ISO or null, links from `config/clients.php` merged by slug, only `https://`
or `http://` links kept. Elevation: `whoami.exe /groups` through ProcessRunner
on page load; `High Mandatory Level` shows a warning banner. Bootstrap adds a
shutdown function so a true fatal returns the same JSON 500; `Response::emit`
sends no body once headers are already sent.

Done-test: `php tests/run.php` with `--filter Page`, `PageUi`,
`ClientsParser`, `ClientsApi` and `Elevation` each pass. PageUiTest: picker
options come from ProjectList; an answer holding `<script>` renders escaped;
`DomSinks` finds none of `innerHTML`, `outerHTML`, `insertAdjacentHTML`,
`document.write`, `srcdoc`, `eval(`, `new Function` in `public/assets/*.js`.
PageTest adds: a 403 never creates the token file; a fatal in a child
`php.exe` run of the front controller yields JSON 500 with no `#0 `.
ClientsParserTest: the "Awaiting Rob's outreach" table adds no rows; five
active rows with phase, next milestone, blocker; leads parsed with bold
stripped; `Election 2026-10-26` gives `2026-10-26`, `TBD` null; a missing slug
gets no links; a duplicate slug is reported. ClientsApiTest: foreign Host
rejected; active clients and leads with every field and six link kinds; due
dates soonest first; a `javascript:` link dropped. ElevationTest: fixture
output with `High Mandatory Level` sets the warning, `Medium` does not, a
missing `whoami` sets nothing and does not throw.

### 63. News
- Role: implementer. Risky path: yes (`public/api/`). Depends: 61, 62. Absorbs 31, 32, 33, 34.
- `src/News/FeedParser.php`
- `src/News/FetchNews.php`
- `src/News/Clusterer.php`
- `src/News/Scorer.php`
- `bin/fetch-news.php`
- `config/feeds.php`
- `src/migrations/005_news.sql`
- `public/api/news.php`
- `public/assets/news.js`
- `src/View.php`
- `tests/fixtures/feeds/`
- `tests/FeedParserTest.php`
- `tests/FetchNewsTest.php`
- `tests/ClusterScoreTest.php`
- `tests/NewsApiTest.php`

RSS 2.0, Atom, JSON Feed, plus `hn.algolia.com/api/v1/search?tags=front_page`
for HN points. HTTP(S) only on request and redirect, response cap, timeout,
through `HttpClient`.

Done-test: `php tests/run.php` with `--filter FeedParser`, `FetchNews`,
`ClusterScore` and `NewsApi` each pass. FeedParserTest: each fixture gives
title, URL, date, source, topic; Algolia fixture gives points; a malformed
feed is skipped; an XXE fixture yields no file content. FetchNewsTest: items
stored once across two runs; a `file://` or `ftp://` feed URL refused; an
oversized response skipped; a story URL starting `javascript:` or `data:`
stored as null. ClusterScoreTest: three sources on one story form one
cluster; two unrelated stories form two; the same URL with `utm_*` stripped is
one cluster; two items from one source count once; score rises with sources
and points and falls with age on fixed clocks. NewsApiTest: hottest first,
page size and page 2 offset, topic filter, foreign Host rejected; `news.js`
sets `rel="noopener noreferrer"` and assigns `href` only after an
`http:`/`https:` check (grep); `DomSinks` clean.

### 64. Impact tags
- Role: implementer. Risky path: yes (`public/api/`). Depends: 61, 63. Absorbs 35, 36, 37, 38.
- `src/Profile/ProfileBuilder.php`
- `src/Impact/KeywordMatcher.php`
- `src/Impact/LlmTagger.php`
- `bin/build-profiles.php`
- `bin/tag-impact.php`
- `src/migrations/006_impact.sql`
- `public/api/impact.php`
- `public/api/tag-correction.php`
- `public/assets/news.js`
- `tests/ProfileBuilderTest.php`
- `tests/KeywordMatcherTest.php`
- `tests/LlmTaggerTest.php`
- `tests/ImpactApiTest.php`

Profiles per ProjectList project: exact versions from lockfiles (manifest
constraint when no lock), domains and providers (WHC, DigitalOcean, OVH,
Cloudflare) from `CLAUDE.md` and `_DASHBOARD.md`, each with its source file;
rebuild replaces the whole set. LlmTagger: `bulk` chain, `Prompt`, schema
`{project, kind, reason}`, corrections for the same project as examples.

Done-test: `php tests/run.php` with `--filter ProfileBuilder`,
`KeywordMatcher`, `LlmTagger` and `ImpactApi` each pass. ProfileBuilderTest:
lock version wins over `^11.0`; a provider in `CLAUDE.md` recorded with its
source; malformed `composer.json` skipped; a project deleted between runs has
no profile after the second; the read log holds no `.credentials` path.
KeywordMatcherTest: a `laravel/framework` 11.x story tags the 11.x project; a
CVE ID tags security risk; `Cloudflare` matches; `react` does not match
`reactive`. LlmTaggerTest: story text sits inside the data block; two saved
corrections appear as examples; a kind outside the four is dropped; a project
not in ProjectList is dropped; invalid JSON gives no tag and no crash.
ImpactApiTest: tags return project, kind, reason and a stable tag id; a reason
with `<b>` is escaped on the `textContent` path; guard failures save no
correction; a valid POST saves one correction tied to that tag id.

### 65. Read-only mail client and MIME text
- Role: implementer. Risky path: yes (`src/Mail/`). Depends: done cards only. Absorbs 39, 40.
- `src/Mail/ImapClient.php`
- `src/Mail/ImapStream.php`
- `src/Mail/MimeText.php`
- `config/mail.php`
- `tests/Fakes/FakeImapStream.php`
- `tests/fixtures/mail/account.json`
- `tests/fixtures/mail/mime/`
- `tests/ImapClientTest.php`
- `tests/MimeTextTest.php`

TLS socket, `verify_peer` and `verify_peer_name` true with no way to turn them
off, connect and read timeouts, bounded literals. Allowlist: `CAPABILITY`,
`LOGIN`, `EXAMINE`, `UID SEARCH`, `UID FETCH` with `BODY.PEEK`/`ENVELOPE`/`UID`,
`LOGOUT`. Login strings sent as literals. `config/mail.php` maps label to
credential file path (WHC's canonical host, not `mail.sembr.co`); the password
is registered with `Log` redaction. MimeText returns at most N KB: prefers
`text/plain`, strips HTML-only mail, decodes base64 and quoted-printable,
converts charsets to UTF-8, skips attachments.

Done-test: `php tests/run.php` with `--filter ImapClient` and `MimeText` pass.
ImapClientTest: every command sent is allowlisted; `SELECT`, `STORE`, `COPY`,
`MOVE`, `EXPUNGE`, `APPEND`, `FETCH BODY[]` throw before writing; a password
with `"`, CRLF and `{5}` cannot add a command; a literal over the cap aborts;
the stream context asserts both verify flags; the fixture password appears in
no log line or exception. MimeTextTest on fixtures: multipart/alternative,
HTML-only, ISO-8859-1 quoted-printable, a PDF attachment absent from output,
malformed boundaries with no exception, an oversized body capped.

### 66. Mail digest job and UI
- Role: implementer. Risky path: yes (`src/Mail/`, `public/api/`). Depends: 61, 62, 65. Absorbs 41, 42.
- `src/Mail/Digest.php`
- `bin/mail-digest.php`
- `src/migrations/007_mail.sql`
- `public/api/mail.php`
- `public/assets/mail.js`
- `src/View.php`
- `tests/DigestTest.php`
- `tests/MailApiTest.php`

Summarises on fetch with the `bulk` chain and `Prompt`, output checked by
`StructuredOutput`; stores sender, subject, date, summary, category keyed by
account + mailbox + UIDVALIDITY + UID. The body exists only in memory: never
in a column, job payload, log or error. A TLS failure records "certificate
error" for that account and continues. Rows over 30 days pruned.

Done-test: `php tests/run.php` with `--filter Digest` and `MailApi` pass.
DigestTest: the mail table has no body column; after a run no 20+ character
string from the fixture body appears in the database file, job table or log;
the body sits inside the prompt's data block; a seen key is skipped; a
UIDVALIDITY change re-reads; the same UID in two accounts gives two rows; a
failing account does not stop the next. MailApiTest: sender, subject, summary,
category and no body field; foreign Host rejected; `DomSinks` clean.

### 67. KC numbers
- Role: implementer. Risky path: yes (`public/api/`). Depends: 62. Absorbs 43, 44, 46 (without the weekly note).
- `bin/collect_ga.py`
- `src/Numbers/GaCollector.php`
- `src/Numbers/StatusCollector.php`
- `src/Numbers/BugsinkCollector.php`
- `bin/collect-kc.php`
- `src/migrations/008_numbers.sql`
- `public/api/numbers.php`
- `public/assets/numbers.js`
- `src/View.php`
- `tests/fixtures/numbers/ga_raw.json`
- `tests/CollectGaTest.php`
- `tests/NumbersTest.php`
- `tests/NumbersApiTest.php`

Own read-only script, not `dashboard-collect.py`: GA4 sessions, users, top 10
pages; Search Console clicks, impressions, CTR, position; last 28 days;
read-only scopes; JSON on stdout; no file writes, no Buffer, no GitHub, no
`--index`. `--from-fixture <file>` runs only the transform. Python runs
through ProcessRunner. Bugsink token read from a path in config, never a value.

Done-test: `php tests/run.php` with `--filter CollectGa`, `Numbers` and
`NumbersApi` each pass. CollectGaTest runs `python.exe bin/collect_ga.py
--from-fixture ...` through ProcessRunner and asserts the JSON keys, and by
grep that the script holds `analytics.readonly` and `webmasters.readonly`, no
`buffer`, no `--index`, no `open(` in write mode. NumbersTest: fixture outputs
stored; one failing source stores its error and the others still store; the
config holds the Bugsink token path, not a value. NumbersApiTest: latest
values returned, foreign Host rejected, `DomSinks` clean.

### 68. Chrome extension and Task Scheduler script
- Role: implementer. Risky path: yes (`chrome-extension/`). Depends: 61, 63, 64, 66, 67. Absorbs 27, 51.
- `chrome-extension/manifest.json`
- `chrome-extension/newtab.html`
- `chrome-extension/newtab.js`
- `bin/register-tasks.ps1`
- `tests/ExtensionTest.php`
- `tests/TasksScriptTest.php`

MV3, overrides new tab, navigates to `http://127.0.0.1:8787/`. Tasks under
`\KC Dashboard\`, `-LogonType S4U`, `-MultipleInstances IgnoreNew`,
`-WorkingDirectory` the repo, absolute `c:\xampp\php\php.exe` and script
paths. Worker every minute; fetch-news, tag-impact, mail-digest every 15
minutes; build-profiles nightly; collect-kc daily; `bin/model.php ensure
bonsai` at logon. `-WhatIf` supported.

Done-test: `php tests/run.php` with `--filter Extension` and `TasksScript`
pass. ExtensionTest: manifest is valid JSON, version 3, has
`chrome_url_overrides.newtab`, no `permissions`, `host_permissions` or
`content_scripts`; `newtab.js` targets exactly that URL. TasksScriptTest:
every `bin/*.php` job has a task with its stated interval, S4U, IgnoreNew and
working directory; no `-RunLevel Highest`; a `-WhatIf` run through
ProcessRunner (powershell.exe added to the allowlist for this test only)
registers nothing.

### 69. Security audit
- Role: security-auditor. Risky path: yes. Depends: 60 to 68, and 07 for the HTTP check. Absorbs 53.
- `src/Http/`
- `src/Process/`
- `src/Fs/`
- `src/Claude/`
- `src/Mail/`
- `src/Projects/ProjectList.php`
- `src/Profile/ProfileBuilder.php`
- `src/Sys/Elevation.php`
- `bin/`
- `public/`
- `ops/apache-kc-dashboard.conf`
- `chrome-extension/`

Cross-card audit only; per-card audits run at the coordinator's gate.

Done-test: full `c:\xampp\php\php.exe tests/run.php` passes,
`php ops/http-check.php` exits 0 against the installed vhost, and the audit
report names a test for each spec Security bullet and each accepted review
finding above, or files a card for any without one.

## Waiting on Rob

### 07. Install the vhost (done 2026-10-12)
Installed by the coordinator with Rob's deploy go: `Include` line at the end of
httpd.conf (backup `httpd.conf.bak-2026-10-12b`), `httpd -t` OK, Apache
restarted. Port 80 returns 403 for the whole project folder; 8787 serves
`public/`. `ops/http-check.php`: every check ok except one, moved to card 62.

### 07 (original text)
- Role: Rob (coordinator installs with his go). Risky path: yes. Depends: 06.
- `C:\xampp\apache\conf\httpd.conf`

Include `ops/apache-kc-dashboard.conf`, run `httpd -t`, restart Apache from
the control panel. Best done before card 60 lands (see Flags). Rerun the check
after each API card.

Done-test: `C:\xampp\apache\bin\httpd.exe -t` prints `Syntax OK`, and
`php ops/http-check.php` exits 0.

### 08. Credentials in place (waiting on Rob)
- Role: Rob. Risky path: no. Depends: none. Needed before 65, 66, 67 run for real; their code uses fakes.
- `c:\xampp\htdocs\.credentials\` (Rob's files; nobody reads the values)

One JSON file per mailbox, `{"host","port","user","password"}`: the WHC
mailboxes using WHC's canonical server hostname (valid cert, not
`mail.sembr.co`), and Gmail with an app password (2FA needed). Also a Bugsink
token file and the GA4 service-account JSON path. Rob gives file names only.

Done-test: `Test-Path` on each named file returns True (no `Get-Content`).

### 28. Load the extension (waiting on Rob)
- Role: Rob. Risky path: yes (`chrome-extension/`). Depends: 07, 62, 68.
- `chrome-extension/`

Rob loads it unpacked, opens a new tab, submits one question.

Done-test: Rob confirms the page shows with no elevation warning, and the
Apache access log for port 8787 shows `POST /api/ask.php` with 200.

### 52. Register scheduled tasks (waiting on Rob)
- Role: Rob. Risky path: no. Depends: 68.
- `bin/register-tasks.ps1`

Rob reviews the `-WhatIf` output, then confirms the real run.

Done-test: `Get-ScheduledTask -TaskPath '\KC Dashboard\'` lists every task
from card 68, and after `Start-ScheduledTask` on the worker a queued Ask
Claude job reaches `done`.

## Later (not built now)

Each keeps its revision 2 description and done-test in git history
(`.claude/work/plan.md` before revision 3); re-plan when picked up.

- 25. Open project in terminal. Carries Codex 6 / Fable 18 for `wt.exe`. Card 15's log notes `wt.exe` is a WindowsApps alias realpath may not resolve, and `wt -d` cannot be rechecked at launch.
- 45. Weekly improvement note (`claude -p` untrusted mode over the numbers). Carries Codex 4 / Fable 5 for its prompt.
- 47. Settings table and model update check. Carries Codex 22 / Fable 11.
- 48. Model health strip, minus the elevation warning now in 62. Carries Fable 26 (no `nvidia-smi` from Apache).
- 49. SSL expiry and claude-loop status. Carries Codex 23 / Fable 12.
- 50. Extras UI. Carries Codex 23 / Fable 12.
- ProcessRunner follow-ups from card 15's log: grandchildren survive `taskkill /T` (needs a job object), and whether a failed taskkill still blocks on `proc_close` is unchecked.
