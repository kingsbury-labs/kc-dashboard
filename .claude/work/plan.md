# Plan: KC Dashboard (whole spec), revision 2

Source: `docs/SPEC.md`. Rules: `.claude/project.md`, `CLAUDE.md`. Reviews:
`.claude/work/reviews/codex.md`, `.claude/work/reviews/fable.md`.

Order: runner, logging and the Apache origin first, then guard, queue, process
and filesystem safety, the LLM layer and the `claude` runner, then features
(logic card, then API/UI card). Every card leaves `php tests/run.php` green.
Cards waiting on Rob are parked by the coordinator; code cards use fakes and do
not wait on them unless a card says so.

## Review outcome

Accepted (source, number: card that absorbs it):

- Codex 1, REMOTE_ADDR part only: 10 (guard refuses non-loopback REMOTE_ADDR); its integration-test ask: 06.
- Codex 3, Fable 10, Codex 24 (dashboard-collect.py drags in Buffer, gh, cache writes, prose output, URL Inspection instead of metrics): 43, 44.
- Codex 4, Fable 5 (injection reaching `claude -p`, no output schema): 20, 21, 22, 23, 37, 41, 45.
- Codex 5, Fable 8 (junction TOCTOU, `is_link` false for junctions): 14, 15, 16.
- Codex 6, Fable 3, Fable 18 (one hardened ProcessRunner, `.bat`/`.cmd` refusal, tree kill): 15, 25.
- Codex 7, Fable 13, Fable 14 (IMAP TLS, quoting, credential format, `mail.sembr.co` cert): 08, 39, 41.
- Codex 8 (mail key and MIME): 40, 41.
- Codex 9, scheme, size, time and link parts: 32, 34.
- Codex 10, Codex 13, Fable 1, Fable 16 (own origin, only `public/` reachable, real HTTP checks): 06, 07, 10, 27.
- Codex 11, Fable 17 (exact Host/Origin, read `HTTP_HOST`): 10.
- Codex 12, atomic token file, errors and logs: 05, 10, 11.
- Codex 14 (no worker registry): 13.
- Codex 15, Fable 22 (fallback on the current call, cold Ollama load): 18, 23.
- Codex 16, Fable 4 (nothing starts llama-server, VRAM): 09, 18, 19, 48.
- Codex 17, Fable 21 (clients tests, two tables): 29, 30.
- Codex 18, Fable 28 (profile schema, lockfile versions): 35.
- Codex 19 (impact tags never shown): 38.
- Codex 20, Fable 2 (bodies never stored): 41, 42.
- Codex 21 (no-store, CSP, nosniff): 11.
- Codex 22, Fable 11 (update flag has no table or display): 47, 48.
- Codex 23, Fable 12 (extras UI, table, loop scan): 49, 50.
- Codex 25, Fable 6 (task triggers, S4U, overlap): 51, 52.
- Codex 26, Fable 23 (card 04 and model card stale): 02, 03 marked done.
- Fable 7, Fable 29 (stack traces with arguments, testable responses): 06, 11.
- Fable 9 (database is locked): 01.
- Fable 15 (no HN points source): 31.
- Fable 19 (`innerHTML` grep too narrow): 26.
- Fable 20 (single-cluster pass, injected project names): 33, 37.
- Fable 24 (untestable boundary test): 20.
- Fable 25 (no logger): 05.
- Fable 26 (`nvidia-smi` per page load): 19, 48.
- Fable 27 (elevated Apache): 28, 48.

Rejected:

- Codex 1, remainder, and Codex 2: already fixed. Apache listens on 127.0.0.1:80 and [::1]:80 only (2026-10-12) and `httpd.conf` denies `.credentials`.
- Codex 9, private-address blocking after redirects: feeds are a fixed list Rob edits and responses only become stored text, so there is nothing to exfiltrate to.
- Codex 12, Windows ACLs on `data/token`: single-user PC; another local account is outside the threat model.
- Codex 27: the coordinator's gate already demands mutation evidence per card.
- Codex 28: the gate already runs a security-auditor pass per risky card; card 53 stays as the final cross-card audit.

## Flags on the existing project

- `docs/SPEC.md` is not edited by this plan: the planner may write only this file. Card 04 carries the spec edits the coordinator's decisions require.
- `.claude/project.md` risky paths miss `src/Process/` (it starts `claude`), `src/Fs/` (the junction check) and `ops/` (Apache config). Cards 06, 14, 15 mark themselves risky; the list should be updated.
- The project folder is still under htdocs, so port 80 serves `/_kc-dashboard/data/token` unless the vhost snippet denies the whole folder there. Card 06 makes that a test.
- `gpt-oss:20b` is 13 GB against 8 GB of VRAM. One-model-at-a-time does not make it fit; it runs mostly on CPU. Card 09 asks Rob.
- `wt.exe` splits its arguments on `;`. Card 16 limits project names to `[A-Za-z0-9._-]`.

## Cards

### 01. Test runner and SQLite bootstrap
- Role: implementer. Risky path: no. Depends: none.
- `tests/run.php`
- `tests/RunnerTest.php`
- `tests/fixtures/runner/PassingTest.php`
- `tests/fixtures/runner/FailingTest.php`
- `src/bootstrap.php`
- `src/Db.php`
- `src/migrations/001_init.sql`
- `tests/DbTest.php`

Runner discovers `tests/*Test.php`, `--filter <substring>`, `--dir`, prints
`FAIL <name>` per failure and last line `Tests: N passed, M failed`, exits 1 on
any failure. Db uses PDO SQLite, path injectable, migrations recorded once, and
runs `PRAGMA journal_mode=WAL` and `PRAGMA busy_timeout=5000` on open.

Done-test: `c:\xampp\php\php.exe tests/run.php --filter Runner` and
`--filter Db` pass. `tests/RunnerTest.php` runs the runner on
`tests/fixtures/runner` and asserts exit 1, one `FAIL` line naming the failing
test, `Tests: 1 passed, 1 failed`, and `--filter Passing` exits 0.
`tests/DbTest.php` asserts migrating a temp file twice records one migration
row, and reading back the pragmas gives `wal` and `5000`.

### 02. Decisions: Apache user, Python, claude.exe (done)
- Role: Rob. Risky path: no. Depends: none.
- `docs/SPEC.md`

Done 2026-10-12: the spec's "Decisions already made" holds three dated lines.
Blocks nothing.

Done-test: `Select-String -Path docs/SPEC.md -Pattern '2026-10-12: Apache runs from the XAMPP control panel as Rob'`
finds one line.

### 03. Local models on disk (done)
- Role: Rob. Risky path: no. Depends: none.
- `llm/models/Ternary-Bonsai-2-27B-PTQ1_0.gguf`

Done 2026-10-12 (5.9 GB file present, `gpt-oss:20b` pulled).

Done-test: `Test-Path llm/models/Ternary-Bonsai-2-27B-PTQ1_0.gguf` is True and
`ollama list` shows `gpt-oss:20b`.

### 04. Spec update for the review decisions
- Role: implementer. Risky path: no. Depends: none.
- `docs/SPEC.md`

Edits: URL becomes `http://127.0.0.1:8787/` on its own vhost, DocumentRoot
`public/`, the only reachable folder; Apache loopback-only and `.credentials`
denied in `httpd.conf` (both 2026-10-12); every request checks loopback
`REMOTE_ADDR` and Host exactly `127.0.0.1:8787` or `localhost:8787`, writes add
POST, token and Origin exactly `http://127.0.0.1:8787` or
`http://localhost:8787`; the `.htaccess` bullet goes. Mail bodies are never
stored (keep sender, subject, date, summary, category, account+mailbox+
UIDVALIDITY+UID). One ProcessRunner, absolute allowlisted `.exe` only, `.bat`
and `.cmd` refused because PHP 8.2.12 predates the 8.2.18 `proc_open` fix.
Junctions rejected by `realpath` compare, rechecked at launch. One model in
VRAM at a time; llama-server flags and port 8081; thinking off and
`json_schema` output (20/20 valid JSON, about 1.4 s per headline, 46 tok/s).
`claude -p` on untrusted data runs in an empty temp folder with tools
disallowed. KC numbers come from this repo's `bin/collect_ga.py`; HN points
from the Algolia API; scheduled tasks run S4U.

Done-test: `Select-String -Path docs/SPEC.md` finds each of `127.0.0.1:8787`,
`never stored`, `UIDVALIDITY`, `8.2.18`, `realpath`, `--cache-type-k q8_0`,
`enable_thinking`, `collect_ga.py`, `S4U`, and finds no `.htaccess denies`.

### 05. Logger with redaction
- Role: implementer. Risky path: no. Depends: 01.
- `src/Log.php`
- `tests/LogTest.php`

Writes to `data/app.log`. Holds a redaction set (token value, every value read
from a credential file) and replaces any occurrence, in strings and nested
arrays, with `[redacted]`.

Done-test: `php tests/run.php --filter Log` passes; asserts a nested array
holding a registered secret is written with `[redacted]` and the secret string
is absent from the log file, and a secret inside an exception message is also
redacted.

### 06. Apache vhost snippet and HTTP check script
- Role: implementer. Risky path: yes (Apache config, the `.htaccess` concern; `ops/` should join the list).
- Depends: 01.
- `ops/apache-kc-dashboard.conf`
- `ops/http-check.php`
- `tests/VhostConfTest.php`

Snippet: `Listen 127.0.0.1:8787`, `Listen [::1]:8787`, one vhost on both,
`DocumentRoot` = `C:/xampp/htdocs/_kc-dashboard/public`, `Options -Indexes`,
`php_admin_flag display_errors off`, and a `Directory` deny so port 80 serves
nothing from `_kc-dashboard`. `ops/http-check.php` makes real HTTP requests
(curl, outside the unit suite): every `public/api/*.php` it finds plus the page
refuse `Host: evil.com`; write endpoints refuse GET, no token, bad Origin; on
8787, `/data/token`, `/src/bootstrap.php`, `/tests/run.php`, `/.git/HEAD`
return 403 or 404; on port 80, `/_kc-dashboard/` and
`/_kc-dashboard/data/token` return 403; no body contains the token or `#0 `
stack frames; responses carry `Cache-Control: no-store`.

Done-test: `php tests/run.php --filter VhostConf` passes; it parses the
snippet and asserts both Listen lines, the DocumentRoot ending `/public`,
`-Indexes`, `display_errors off`, the port-80 deny on the project folder, and
no `Listen 80` or `0.0.0.0`. `php -l ops/http-check.php` exits 0.

### 07. Install the vhost (waiting on Rob)
- Role: Rob (coordinator installs with his go). Risky path: yes. Depends: 06.
- `C:\xampp\apache\conf\httpd.conf`

Include `ops/apache-kc-dashboard.conf`, run `httpd -t`, restart Apache from
the control panel. Rerun the check after card 11 and after each API card.

Done-test: `C:\xampp\apache\bin\httpd.exe -t` prints `Syntax OK`, and
`php ops/http-check.php` exits 0.

### 08. Credentials in place (waiting on Rob)
- Role: Rob. Risky path: no. Depends: none.
- `c:\xampp\htdocs\.credentials\` (Rob's files; nobody reads the values)

One JSON file per mailbox, `{"host","port","user","password"}`: the WHC
mailboxes using WHC's canonical server hostname (valid cert, not
`mail.sembr.co`), and Gmail with an app password (2FA needed). Also a Bugsink
token file and the GA4 service-account JSON path. Rob gives file names only.

Done-test: `Test-Path` on each named file returns True (no `Get-Content`).

### 09. Fallback model decision (waiting on Rob)
- Role: Rob. Risky path: no. Depends: none.
- `config/llm.php`

`gpt-oss:20b` (13 GB) cannot sit in 8 GB of VRAM. Rob picks: keep it with a
budget measured on a real CPU-offload run, or swap for a model that fits (4 GB
class). Card 23 reads whatever he picks from config.

Done-test: `config/llm.php` names the chosen Ollama model and its budget, and
a comment line gives the measured tokens per second with the date.

### 10. Request guard and install token
- Role: implementer. Risky path: yes (`src/Http/`). Depends: 01, 05.
- `src/Http/RequestGuard.php`
- `src/Http/Token.php`
- `tests/RequestGuardTest.php`
- `tests/TokenTest.php`

`checkRead()`: `REMOTE_ADDR` is `127.0.0.1` or `::1`; `HTTP_HOST` byte-equal to
`127.0.0.1:8787` or `localhost:8787`; `SERVER_NAME` and forwarded headers
ignored. `checkWrite()` adds POST, token header via `hash_equals`, Origin
byte-equal to `http://` plus the Host used. Token: 32 random bytes hex,
created with `fopen(..., 'x')`, registered with `Log` redaction.

Done-test: `php tests/run.php --filter RequestGuard` and `--filter Token`
pass. Every rejection case sets `SERVER_NAME => 'localhost'`. Rejects:
`REMOTE_ADDR` `192.168.1.5`, Host `localhost`, `LOCALHOST:8787`,
`localhost.:8787`, `localhost:8787,evil.com`, `[::1]:8787`, `evil.com`; on
write: GET, no token, wrong token, short token, Origin `null`, missing,
`chrome-extension://abc`, `http://localhost:8787.evil.com`,
`http://user@localhost:8787`, and Origin `http://localhost:8787` with Host
`127.0.0.1:8787`. Accepts both exact pairs. TokenTest: one file, second call
returns the same value, mtime unchanged.

### 11. Responses, error handling and front controller
- Role: implementer. Risky path: yes (`public/`). Depends: 10.
- `src/Http/Response.php`
- `src/bootstrap.php`
- `src/View.php`
- `public/index.php`
- `tests/PageTest.php`

Page and APIs return `[status, headers, body]`; the front controller emits it.
Every response carries `Cache-Control: no-store`, `Pragma: no-cache`, a CSP
(`default-src 'self'`, `form-action 'self' https://www.google.com`),
`X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`. Bootstrap
sets `display_errors=0`, `html_errors=0`, `zend.exception_ignore_args=1` and a
handler returning JSON 500 with no message. Page runs `checkRead()`, puts the
token in a `<meta>` tag, has the Google search form.

Done-test: `php tests/run.php --filter Page` passes; foreign Host gives 403
with no token in the body; the good pair gives the meta token, the form with
`action="https://www.google.com/search"` and `method="get"`, and all five
headers; an exception thrown inside the guard path yields status 500 whose
body contains neither the token nor `#0 `.

### 12. Job queue
- Role: implementer. Risky path: no. Depends: 01.
- `src/Jobs/Queue.php`
- `src/migrations/002_jobs.sql`
- `tests/QueueTest.php`

Enqueue, atomic claim, complete, fail with error text, stale-claim recovery,
queue length.

Done-test: `php tests/run.php --filter Queue` passes; two claims on one job
give one winner, a failed job stores its error, a claim older than the timeout
returns to queued.

### 13. Worker and job registry
- Role: implementer. Risky path: no. Depends: 12.
- `src/Jobs/Registry.php`
- `bin/worker.php`
- `tests/WorkerTest.php`

Registry maps job type to handler class; feature cards add one line each.
Worker checks its time budget between jobs and exits; an unknown type fails,
not retried forever.

Done-test: `php tests/run.php --filter Worker` passes; enqueues a test-type
job in a temp database, runs `bin/worker.php` as a real process with that
database and a test registry, and asserts the job is `done` with the
handler's output; an unknown type ends `failed`.

### 13a. Test runner fails on a broken test file (do before 14)
- Role: implementer. Risky path: no, but every gate depends on it.
- `tests/run.php`
- `tests/RunnerSelfTest.php` (or a fixture folder under `tests/fixtures/runner/`
  that discovery skips)

Found by card 14's worker on 2026-10-12: a test file with a PHP parse error
makes `tests/run.php` exit 0 and report a pass. Each test file must be
loaded so that a parse error, a fatal error, an uncaught exception at load
time, or a file that defines no tests is a `FAIL <file>` line and a non-zero
exit. Running each file in a child `php -l` first, or loading it in a
subprocess, are both fine; pick the simplest that catches all four.

Done-test: `php tests/run.php --filter RunnerSelf` passes, and the self-test
runs `tests/run.php` as a child process against fixture folders holding (a) a
file with a syntax error, (b) a file that throws at load, (c) a file with one
failing test, (d) a clean passing file, and asserts the exit code is non-zero
for a, b and c and zero for d, with a `FAIL` line naming the file for a and b.
Mutation: make the runner swallow the load error again; the self-test fails.
After this card lands, re-run the full suite once and confirm the test count
did not drop (no earlier test file was silently skipped).

### 14. Safe directory check (junctions)
- Role: implementer. Risky path: yes (should be listed; it gates every launch cwd). Depends: 01.
- `src/Fs/SafeDir.php`
- `tests/SafeDirTest.php`

`isDirectChild(root, name)` is true only when
`realpath(root . '\\' . name)` equals `realpath(root) . '\\' . name` exactly.
No `is_link` (false for junctions on PHP 8.2.12).

Done-test: `php tests/run.php --filter SafeDir` passes; in a temp root, a
real junction made with `New-Item -ItemType Junction` pointing outside is
rejected, a plain folder accepted, `..` and `a\b` rejected.

### 15. ProcessRunner
- Role: implementer. Risky path: yes (starts `claude`; `src/Process/` should join the list). Depends: 05, 14.
- `src/Process/ProcessRunner.php`
- `config/executables.php`
- `tests/Fakes/FakeProcessRunner.php`
- `tests/ProcessRunnerTest.php`

The one way to start a process. First argv item must be an absolute path in
`config/executables.php` (claude.exe, wt.exe, python.exe, php.exe, taskkill.exe,
whoami.exe, nvidia-smi.exe, llama-server.exe) to an existing `.exe`; `.bat`,
`.cmd`, `.ps1`, relative paths and PATH lookup throw before `proc_open`.
`bypass_shell`, argv array, explicit environment (allowlisted variables only),
optional `cwd` with a `SafeDir` root that is rechecked immediately before
`proc_open`. Timeout runs `taskkill.exe /PID <pid> /T /F`, then `proc_close`.

Done-test: `php tests/run.php --filter ProcessRunner` passes; real `php.exe`
echoing `json_encode($argv)` round-trips arguments containing spaces, `"`,
`&`, `|`, `%PATH%`, `^` and `..\..` unchanged; a temp `.bat` that would write a
marker is refused and the marker never appears; `.cmd`, a relative path and
an unlisted `.exe` are refused; a cwd swapped for a junction after
validation is refused; a sleeping child is killed within 5 s of a 1 s
timeout; the child sees no variable outside the allowlist.

### 16. Project picker
- Role: implementer. Risky path: no (covered by card 53). Depends: 14.
- `src/Projects/ProjectList.php`
- `tests/ProjectListTest.php`

Lists folders directly under a root (default `c:\xampp\htdocs`), fresh each
call, names matching `^[A-Za-z0-9._-]+$` only, skipping dot folders and
anything `SafeDir` rejects. Resolves a name only if it is in the listing.

Done-test: `php tests/run.php --filter ProjectList` passes; temp root with
`alpha/`, `.credentials/`, `.hidden/`, `semi;colon/`, a file and a real
junction gives `['alpha']`; `..`, `alpha/..`, `alpha\sub`, `C:\Windows`,
`.credentials`, the junction and an empty name resolve to null.

### 17. LLM chat client (OpenAI-style)
- Role: implementer. Risky path: no. Depends: 01.
- `src/Llm/ChatClient.php`
- `src/Llm/OpenAiClient.php`
- `src/Net/HttpClient.php`
- `src/Net/CurlHttpClient.php`
- `tests/Fakes/FakeHttpClient.php`
- `config/llm.php`
- `tests/OpenAiClientTest.php`

`config/llm.php`: `bonsai` at `http://127.0.0.1:8081/v1`, `ollama`, `claude`,
each with a budget. Requests send `chat_template_kwargs: {enable_thinking:
false}` and `response_format` of type `json_schema` when a schema is given.
`CurlHttpClient` has connect and total timeouts and a response size cap.

Done-test: `php tests/run.php --filter OpenAiClient` passes; asserts the POST
path, model, `enable_thinking` false, the schema in `response_format`, parsed
content and `usage`, tokens per second from fake timing, `BackendDown` on
connection error, HTTP 5xx and timeout.

### 18. Model process manager
- Role: implementer. Risky path: no (launches through card 15). Depends: 15, 17.
- `src/Llm/ModelManager.php`
- `bin/model.php`
- `tests/ModelManagerTest.php`

One model in VRAM. `ensure('bonsai')` unloads Ollama (`keep_alive: 0` over
its API, confirmed by `/api/ps` empty), then starts `llm/fork/llama-server.exe`
with `-m <gguf> -ngl 99 -fa on -c 8192 --cache-type-k q8_0 --cache-type-v q8_0
--jinja --host 127.0.0.1 --port 8081`, records the pid in `data/`, and waits
for `/health` 200 within a startup limit. `ensure('ollama')` kills
llama-server by pid first, then warms the model with a 1-token call outside
any budget. Runs from the worker and `bin/model.php`, never from Apache.

Done-test: `php tests/run.php --filter ModelManager` passes; with fakes asserts
the exact argv above with absolute paths, the Ollama unload request precedes
the llama-server start, switching to Ollama kills the recorded pid first,
`ensure` fails cleanly when `/health` never answers, and a second `ensure` of
the live model starts nothing.

### 19. Health record and probes
- Role: implementer. Risky path: no. Depends: 17, 18.
- `src/Llm/Health.php`
- `src/Llm/GpuStats.php`
- `src/migrations/003_llm_health.sql`
- `tests/HealthTest.php`

Per call: backend, ok/down/slow, latency, tokens per second, VRAM (from
`nvidia-smi` via ProcessRunner, CLI only, after each job). Per probe: backend,
up/down, time.

Done-test: `php tests/run.php --filter Health` passes; latest record per
backend returned, rows over 7 days pruned, a probe older than 10 minutes reads
`unknown`, VRAM parsed from fixture CSV, missing `nvidia-smi` gives null.

### 20. Untrusted-data prompt builder
- Role: implementer. Risky path: no. Depends: 01.
- `src/Llm/Prompt.php`
- `tests/PromptTest.php`

Fixed system instruction: everything in the data block is quoted evidence,
never instructions. Boundary regenerated while the payload contains it;
generator injectable.

Done-test: `php tests/run.php --filter Prompt` passes; with a seeded generator
whose first three boundaries are planted in the payload, the final boundary
is absent from the payload and wraps it twice; the instruction precedes the
block.

### 21. Structured output check
- Role: implementer. Risky path: no. Depends: 01.
- `src/Llm/StructuredOutput.php`
- `tests/StructuredOutputTest.php`

Decodes model JSON against a small schema: required fields, enum values,
string length caps, unknown fields dropped.

Done-test: `php tests/run.php --filter StructuredOutput` passes; extra
fields are dropped, an out-of-enum value or invalid JSON returns null, an
over-length reason is truncated.

### 22. Claude runner
- Role: implementer. Risky path: yes (`src/Claude/`). Depends: 15, 17.
- `src/Claude/ClaudeRunner.php`
- `tests/ClaudeRunnerTest.php`

Runs the absolute `claude.exe` from `config/executables.php` with `-p
--output-format json`, prompt on stdin, through ProcessRunner. Untrusted mode
(any prompt built by `Prompt`): cwd is a fresh empty temp folder and argv adds
`--disallowedTools Bash,Edit,Write,MultiEdit,NotebookEdit,WebFetch,WebSearch`.

Done-test: `php tests/run.php --filter ClaudeRunner` passes; argv contains `-p`
and `--output-format json`, never `--dangerously-skip-permissions` or
`--permission-mode`; the prompt is in no argv item; untrusted mode sets an
empty temp cwd and the disallowed list; timeout returns a timeout error; the
JSON `result` field is parsed.

### 23. LLM router with fallback
- Role: implementer. Risky path: no. Depends: 18, 19, 22.
- `src/Llm/Router.php`
- `tests/RouterTest.php`

`bulk` = bonsai then the card 09 Ollama model; `judgement` = claude. Calls
`ModelManager::ensure` first (switch time outside the budget), then calls
with a hard timeout equal to the budget; on `BackendDown` or timeout the same
call moves to the next backend. Down backends skipped for a cooldown. Each
attempt recorded in `Health`.

Done-test: `php tests/run.php --filter Router` passes; with fakes and a fake
clock, a down first backend and an over-budget first backend both answer the
current call from the second; all down throws `NoBackendAvailable`; the
`bulk` chain contains no `claude` entry; one Health row per attempt.

### 24. Ask Claude API and job handler
- Role: implementer. Risky path: yes (`public/api/`, `src/Claude/`). Depends: 11, 13, 16, 22.
- `public/api/ask.php`
- `public/api/answers.php`
- `src/Jobs/AskHandler.php`
- `src/migrations/004_asks.sql`
- `tests/AskApiTest.php`

`ask.php`: `checkWrite()`, project via `ProjectList` (blank = home folder),
enqueue. Handler runs `ClaudeRunner` with cwd under the SafeDir root
recheck; registered in `Registry`. Keeps 20 answers.

Done-test: `php tests/run.php --filter AskApi` passes; each card 10 rejection
and an unknown project create zero jobs; a valid request creates one; the
handler stores the fake answer; a 21st drops the oldest; `Registry` maps
`ask`.

### 25. Open project in terminal
- Role: implementer. Risky path: yes (`src/Claude/`, `public/api/`). Depends: 11, 15, 16.
- `src/Claude/TerminalLauncher.php`
- `public/api/terminal.php`
- `tests/TerminalLauncherTest.php`

From Apache (runs as Rob): absolute `wt.exe` with `-d <path>
<absolute claude.exe>`, through ProcessRunner with the SafeDir recheck.

Done-test: `php tests/run.php --filter TerminalLauncher` passes; guard
failures launch nothing; argv is exactly the absolute wt.exe, `-d`, the
`ProjectList` path, the absolute claude.exe; no bypass flag; a project
swapped for a junction after listing launches nothing.

### 26. Page UI: search, Ask Claude, answers
- Role: implementer. Risky path: no. Depends: 24, 25.
- `public/assets/app.js`
- `public/assets/app.css`
- `public/index.php`
- `tests/Support/DomSinks.php`
- `tests/PageUiTest.php`

ES-module JS sends the token header on POST, polls answers, writes with
`textContent`.

Done-test: `php tests/run.php --filter PageUi` passes; picker options come
from `ProjectList`; an answer with `<script>` renders escaped; `DomSinks`
finds none of `innerHTML`, `outerHTML`, `insertAdjacentHTML`,
`document.write`, `srcdoc`, `eval(`, `new Function` in `public/assets/*.js`.

### 27. Chrome extension
- Role: implementer. Risky path: yes (`chrome-extension/`). Depends: none.
- `chrome-extension/manifest.json`
- `chrome-extension/newtab.html`
- `chrome-extension/newtab.js`
- `tests/ExtensionTest.php`

MV3, overrides new tab, navigates to `http://127.0.0.1:8787/`.

Done-test: `php tests/run.php --filter Extension` passes; manifest is valid
JSON, version 3, has `chrome_url_overrides.newtab`, no `permissions`,
`host_permissions` or `content_scripts`; `newtab.js` targets exactly that URL.

### 28. Load the extension (waiting on Rob)
- Role: Rob. Risky path: yes (`chrome-extension/`). Depends: 07, 26, 27.
- `chrome-extension/`

Rob loads it unpacked, opens a new tab, submits one question, clicks the
terminal button.

Done-test: Rob confirms the page shows, a terminal window opens in the
project, the health strip (once card 48 lands) shows no elevation warning,
and the Apache access log for port 8787 shows `POST /api/ask.php` with 200.

### 29. Clients parser
- Role: implementer. Risky path: no. Depends: 01.
- `src/Clients/DashboardParser.php`
- `src/Clients/PipelineParser.php`
- `config/clients.php`
- `tests/fixtures/clients/_DASHBOARD.md`
- `tests/fixtures/clients/_PIPELINE.md`
- `tests/ClientsParserTest.php`

Parses only the table under `## Active Projects`; strips markdown emphasis,
arrows and emoji in cells. Due: ISO date when present, else null.

Done-test: `php tests/run.php --filter ClientsParser` passes; the fixture's
"Awaiting Rob's outreach" table contributes no rows; five active rows with
phase, next milestone, blocker; pipeline leads parsed with bold stripped;
`Election 2026-10-26` gives `2026-10-26`, `TBD` null; `config/clients.php`
links (site, GA4, Search Console, billing, repo, folder) merge by slug; a
missing slug gets no links, a duplicate slug is reported.

### 30. Clients API and panel
- Role: implementer. Risky path: yes (`public/api/`). Depends: 11, 26, 29.
- `public/api/clients.php`
- `public/assets/clients.js`
- `tests/ClientsApiTest.php`

Done-test: `php tests/run.php --filter ClientsApi` passes; foreign Host
rejected; JSON has active clients and leads, each with every field and the six
link kinds; due dates sorted soonest first; a link not starting `https://` or
`http://` is dropped; `DomSinks` clean.

### 31. Feed parser and HN points
- Role: implementer. Risky path: no. Depends: 01.
- `src/News/FeedParser.php`
- `config/feeds.php`
- `tests/fixtures/feeds/`
- `tests/FeedParserTest.php`

RSS 2.0, Atom, JSON Feed, plus `hn.algolia.com/api/v1/search?tags=front_page`
for HN with `points`.

Done-test: `php tests/run.php --filter FeedParser` passes; each fixture gives
title, URL, date, source, topic; the Algolia fixture gives points; a malformed
feed is skipped; an XXE fixture yields no file content.

### 32. News fetch job
- Role: implementer. Risky path: no. Depends: 17, 31.
- `src/News/FetchNews.php`
- `bin/fetch-news.php`
- `src/migrations/005_news.sql`
- `tests/FetchNewsTest.php`

HTTP(S) only on request and redirect, response cap, timeout.

Done-test: `php tests/run.php --filter FetchNews` passes; with `FakeHttpClient`
items are stored once across two runs, a `file://` or `ftp://` feed URL is
refused, an oversized response is skipped, a story URL starting
`javascript:` or `data:` is stored as null.

### 33. Story clustering and scoring
- Role: implementer. Risky path: no. Depends: 32.
- `src/News/Clusterer.php`
- `src/News/Scorer.php`
- `tests/ClusterScoreTest.php`

Done-test: `php tests/run.php --filter ClusterScore` passes; three sources on
one story form one cluster; two unrelated stories form two; the same URL with
`utm_*` stripped is one cluster; two items from one source count once; score
rises with sources and HN points and falls with age on fixed clocks.

### 34. News API and UI
- Role: implementer. Risky path: yes (`public/api/`). Depends: 11, 26, 33.
- `public/api/news.php`
- `public/assets/news.js`
- `tests/NewsApiTest.php`

Done-test: `php tests/run.php --filter NewsApi` passes; hottest first, page
size and page 2 offset, topic filter, foreign Host rejected; `news.js` sets
`rel="noopener noreferrer"` on links and only assigns `href` after an
`http:`/`https:` check (asserted by grep); `DomSinks` clean.

### 35. Project profile builder
- Role: implementer. Risky path: no (covered by card 53). Depends: 16, 29.
- `src/Profile/ProfileBuilder.php`
- `bin/build-profiles.php`
- `tests/ProfileBuilderTest.php`

Per `ProjectList` project: packages with exact versions from
`composer.lock`/`package-lock.json` (constraint from the manifest when no
lock), domains and host providers (WHC, DigitalOcean, OVH, Cloudflare) from
`CLAUDE.md` and `_DASHBOARD.md`, each with its source file. Rebuild replaces
the whole set.

Done-test: `php tests/run.php --filter ProfileBuilder` passes; on a temp root
the lock version wins over `^11.0`; a provider named in `CLAUDE.md` is
recorded with its source; a malformed `composer.json` is skipped; a project
deleted between runs has no profile after the second run; the read log holds
no `.credentials` path.

### 36. Impact keyword pass
- Role: implementer. Risky path: no. Depends: 33, 35.
- `src/Impact/KeywordMatcher.php`
- `src/migrations/006_impact.sql`
- `tests/KeywordMatcherTest.php`

Done-test: `php tests/run.php --filter KeywordMatcher` passes; a
`laravel/framework` 11.x story tags the project on 11.x; a CVE ID tags
security risk; `Cloudflare` matches the provider; `react` does not match
`reactive`.

### 37. Impact model pass
- Role: implementer. Risky path: no. Depends: 20, 21, 23, 36.
- `src/Impact/LlmTagger.php`
- `bin/tag-impact.php`
- `tests/LlmTaggerTest.php`

`bulk` chain, `Prompt`, schema `{project, kind, reason}`, corrections for the
same project as examples.

Done-test: `php tests/run.php --filter LlmTagger` passes; story text is inside
the data block; two saved corrections appear as examples; a kind outside the
four is dropped; a project not in `ProjectList` is dropped; invalid JSON gives
no tag and no crash.

### 38. Impact tags API, UI and mark-wrong
- Role: implementer. Risky path: yes (`public/api/`). Depends: 11, 34, 37.
- `public/api/impact.php`
- `public/api/tag-correction.php`
- `public/assets/news.js`
- `tests/ImpactApiTest.php`

Done-test: `php tests/run.php --filter ImpactApi` passes; tags return project,
kind, one-line reason and a stable tag id per story; a reason with `<b>` is
escaped on the `textContent` path; guard failures save no correction; a valid
POST saves one correction tied to that tag id.

### 39. Read-only IMAP client
- Role: implementer. Risky path: yes (`src/Mail/`). Depends: 05.
- `src/Mail/ImapClient.php`
- `src/Mail/ImapStream.php`
- `tests/Fakes/FakeImapStream.php`
- `config/mail.php`
- `tests/fixtures/mail/account.json`
- `tests/ImapClientTest.php`

TLS socket, `verify_peer` and `verify_peer_name` true with no option to turn
them off, connect and read timeouts, bounded literals. Allowlist: `CAPABILITY`,
`LOGIN`, `EXAMINE`, `UID SEARCH`, `UID FETCH` with `BODY.PEEK`/`ENVELOPE`/
`UID`, `LOGOUT`. Login strings sent as literals. `config/mail.php` maps label
to credential file path; the password is registered with `Log` redaction.

Done-test: `php tests/run.php --filter ImapClient` passes; every command sent
is allowlisted; `SELECT`, `STORE`, `COPY`, `MOVE`, `EXPUNGE`, `APPEND`,
`FETCH BODY[]` throw before writing; a password with `"`, CRLF and `{5}` cannot
add a command; a literal over the cap aborts; the stream context asserts both
verify flags; the fixture password appears in no log line or exception.

### 40. MIME text extraction
- Role: implementer. Risky path: yes (`src/Mail/`). Depends: 01.
- `src/Mail/MimeText.php`
- `tests/fixtures/mail/mime/`
- `tests/MimeTextTest.php`

Returns at most N KB of plain text: prefers `text/plain`, strips HTML-only
mail to text, decodes base64 and quoted-printable, converts charsets to UTF-8,
skips attachments.

Done-test: `php tests/run.php --filter MimeText` passes on fixtures:
multipart/alternative, HTML-only, ISO-8859-1 quoted-printable, a PDF
attachment (absent from output), malformed boundaries (no exception), and an
oversized body (output capped).

### 41. Mail digest job
- Role: implementer. Risky path: yes (`src/Mail/`). Depends: 13, 20, 21, 23, 39, 40.
- `src/Mail/Digest.php`
- `bin/mail-digest.php`
- `src/migrations/007_mail.sql`
- `tests/DigestTest.php`

Summarises on fetch with the `bulk` chain; stores sender, subject, date,
summary, category keyed by account+mailbox+UIDVALIDITY+UID. The body exists
only in memory: never in a column, job payload, log or error. A TLS failure
records "certificate error" for that account and continues. Rows over 30 days
pruned.

Done-test: `php tests/run.php --filter Digest` passes; the mail table has no
body column; after a run, no string of 20+ characters from the fixture body
appears in the database file, job table or log; a seen key is skipped; a
UIDVALIDITY change re-reads; the same UID in two accounts gives two rows; a
failing account does not stop the next.

### 42. Mail digest UI
- Role: implementer. Risky path: yes (`public/api/`). Depends: 11, 26, 41.
- `public/api/mail.php`
- `public/assets/mail.js`
- `tests/MailApiTest.php`

Done-test: `php tests/run.php --filter MailApi` passes; returns sender,
subject, summary, category and no body field; foreign Host rejected;
`DomSinks` clean.

### 43. GA4 and Search Console script
- Role: implementer. Risky path: no. Depends: 15.
- `bin/collect_ga.py`
- `tests/fixtures/numbers/ga_raw.json`
- `tests/CollectGaTest.php`

Own read-only copy, not `dashboard-collect.py`: GA4 sessions, users and top
10 pages, and Search Console clicks, impressions, CTR and position, last 28
days, read-only scopes, JSON on stdout, no file writes, no Buffer or GitHub,
no `--index`. `--from-fixture <file>` runs only the transform.

Done-test: `php tests/run.php --filter CollectGa` passes; runs `python.exe
bin/collect_ga.py --from-fixture ...` through ProcessRunner and asserts the
JSON keys; asserts by grep that the script holds `analytics.readonly` and
`webmasters.readonly`, no `buffer`, no `--index`, no `open(` in write mode.

### 44. KC numbers collectors
- Role: implementer. Risky path: no. Depends: 15, 17, 43.
- `src/Numbers/GaCollector.php`
- `src/Numbers/StatusCollector.php`
- `src/Numbers/BugsinkCollector.php`
- `bin/collect-kc.php`
- `src/migrations/008_numbers.sql`
- `tests/NumbersTest.php`

Done-test: `php tests/run.php --filter Numbers` passes; fixture outputs are
stored; one failing source stores its error and the others still store; the
config holds the Bugsink token path, not a value.

### 45. Weekly improvement note
- Role: implementer. Risky path: yes (`src/Claude/` via `judgement`). Depends: 13, 20, 22, 44.
- `src/Numbers/WeeklyNote.php`
- `bin/weekly-note.php`
- `tests/WeeklyNoteTest.php`

Enqueues a `weekly-note` job, handled by the worker (registered), which
passes every collector string through `Prompt` and runs `ClaudeRunner` in
untrusted mode.

Done-test: `php tests/run.php --filter WeeklyNote` passes; one job per ISO
week; a Bugsink title appears only inside the data block; the runner is
called in untrusted mode; the note is saved.

### 46. KC numbers UI
- Role: implementer. Risky path: yes (`public/api/`). Depends: 11, 26, 45.
- `public/api/numbers.php`
- `public/assets/numbers.js`
- `tests/NumbersApiTest.php`

Done-test: `php tests/run.php --filter NumbersApi` passes; latest values and
the weekly note, foreign Host rejected, `DomSinks` clean.

### 47. Settings table and model update check
- Role: implementer. Risky path: no. Depends: 17.
- `src/migrations/009_settings.sql`
- `src/Settings.php`
- `src/Llm/UpdateCheck.php`
- `bin/check-llm-updates.php`
- `tests/UpdateCheckTest.php`

Writes `llm.update_available`, current and latest ids, check time and error.
Never downloads.

Done-test: `php tests/run.php --filter UpdateCheck` passes; a newer fixture
tag sets the flag, the same tag clears it, a failed fetch stores the error and
keeps the last result, the fake HTTP log has only GETs to the two API hosts.

### 48. Model health strip
- Role: implementer. Risky path: yes (`public/api/`). Depends: 11, 19, 26, 47.
- `public/api/health.php`
- `public/assets/health.js`
- `tests/HealthApiTest.php`

Reads `Health` and `Settings`; no `nvidia-smi` here. Runs
`C:\Windows\System32\whoami.exe /groups` through ProcessRunner for the
elevation warning.

Done-test: `php tests/run.php --filter HealthApi` passes; returns live backend
from a fresh probe (`unknown` when stale), last tokens per second, VRAM from
the last job row, queue length, the update flag; fixture `whoami` output with
`High Mandatory Level` sets the warning.

### 49. SSL expiry and claude-loop status
- Role: implementer. Risky path: no. Depends: 16, 29, 47.
- `src/Extras/SslCheck.php`
- `src/Extras/LoopStatus.php`
- `bin/check-ssl.php`
- `tests/ExtrasTest.php`

Targets: `https://` site links from `config/clients.php` plus `mail.sembr.co`.
Results stored via `Settings`. `LoopStatus` iterates `ProjectList`.

Done-test: `php tests/run.php --filter Extras` passes; a fixture cert at 5
days is flagged, an expired one shows expired, a non-https link is not
checked, a temp project with `.claude/work/loop/` is listed, a junctioned one
is not.

### 50. Extras UI
- Role: implementer. Risky path: yes (`public/api/`). Depends: 11, 26, 49.
- `public/api/extras.php`
- `public/assets/extras.js`
- `tests/ExtrasApiTest.php`

Done-test: `php tests/run.php --filter ExtrasApi` passes; due dates, SSL
results and loop status returned; foreign Host rejected; `DomSinks` clean.

### 51. Task Scheduler script
- Role: implementer. Risky path: no. Depends: 13, 18, 32, 37, 41, 44, 45, 47, 49.
- `bin/register-tasks.ps1`
- `tests/TasksScriptTest.php`

Tasks under `\KC Dashboard\`, `-LogonType S4U`, `-MultipleInstances
IgnoreNew`, `-WorkingDirectory` the repo, absolute `c:\xampp\php\php.exe` and
script paths. Worker every minute; fetch-news, tag-impact, mail-digest every
15 minutes; build-profiles nightly; collect-kc daily; weekly-note and
check-llm-updates weekly; check-ssl daily; `bin/model.php ensure bonsai` at
logon. `-WhatIf` supported.

Done-test: `php tests/run.php --filter TasksScript` passes; every `bin/*.php`
job has a task with its stated interval, S4U, IgnoreNew and working
directory; no `-RunLevel Highest`; `-WhatIf` run through ProcessRunner
(powershell.exe added to the allowlist for this test only) registers nothing.

### 52. Register scheduled tasks (waiting on Rob)
- Role: Rob. Risky path: no. Depends: 51.
- `bin/register-tasks.ps1`

Rob reviews the `-WhatIf` output, then confirms the real run.

Done-test: `Get-ScheduledTask -TaskPath '\KC Dashboard\'` lists every task
from card 51, and after `Start-ScheduledTask` on the worker a queued Ask
Claude job reaches `done`.

### 53. Security audit
- Role: security-auditor. Risky path: yes. Depends: all code cards.
- `src/Http/`
- `src/Process/`
- `src/Fs/`
- `src/Claude/`
- `src/Mail/`
- `src/Projects/ProjectList.php`
- `src/Profile/ProfileBuilder.php`
- `public/`
- `ops/apache-kc-dashboard.conf`
- `chrome-extension/`

Cross-card audit only; per-card audits run at the coordinator's gate.

Done-test: full `c:\xampp\php\php.exe tests/run.php` passes,
`php ops/http-check.php` exits 0 against the installed vhost, and the audit
report names a test for each spec Security bullet and each accepted review
finding above, or files a card for any without one.
