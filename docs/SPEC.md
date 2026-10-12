# KC Dashboard spec

Rob's personal Chrome new-tab page for Kingsbury Creative. Runs only on his PC,
under XAMPP, at `http://127.0.0.1:8787/` on its own vhost whose DocumentRoot is
`public/`, the only folder reachable over HTTP. Nobody else uses it.

## Decisions already made (Rob, 2026-10-12)

- Local XAMPP only. Never deployed, never reachable from outside the PC.
- The "ask Claude" box runs `claude -p` (Rob's account, no API key) with the
  working directory set to the project he picks from `c:\xampp\htdocs\*`, or
  his home folder when none is picked.
- Mail is read-only, from both the WHC IMAP mailboxes and Gmail. Nothing is
  ever written, flagged, moved or deleted.
- Local model for bulk work: Ternary-Bonsai-2-27B (PTQ1_0) on the PrismML
  llama.cpp fork's `llama-server`, with `gpt-oss:20b` on Ollama as the
  fallback. Both speak the OpenAI-style `/v1/chat/completions` API, so the
  switch is one config value. `claude -p` handles the few calls that need
  judgement (weekly summaries). Only one model is in VRAM at a time: switching
  backends unloads the other first.
- llama-server runs as `llm/fork/llama-server.exe -m <gguf> -ngl 99 -fa on
  -c 8192 --cache-type-k q8_0 --cache-type-v q8_0 --jinja --host 127.0.0.1
  --port 8081`. Requests send `chat_template_kwargs: {enable_thinking: false}`
  and a `json_schema` `response_format`. Measured: 20/20 valid JSON, about
  1.4 s per headline, 46 tok/s.
- No "training". Each night a project profile is rebuilt from every project's
  CLAUDE.md, composer.json, package.json and `clients/_DASHBOARD.md`, and fed
  to the model in the prompt. Rob's corrections to past tags are saved and fed
  back as examples.
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
  no Composer, no npm build. Plain HTML, CSS and ES-module JS.
- SQLite (`data/dashboard.sqlite`, gitignored) for news items, tags, mail
  digests, job queue and settings.
- Background jobs are PHP CLI scripts under `bin/`, run by Windows Task
  Scheduler as Rob, with scheduled tasks registered to run S4U (no stored
  password, runs whether or not Rob is logged on). The page never blocks on a slow fetch; it reads SQLite.
- Tests: a plain PHP test runner (`tests/run.php`), no PHPUnit. Network,
  IMAP, LLM and `claude` calls are behind interfaces with fakes in tests.

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
  `c:\xampp\htdocs`, matched against a fresh listing; no paths from the client.
- `claude -p` runs without permission bypass flags. The prompt goes in on stdin
  or as one escaped argument, never through a shell string.
- Credentials are read from `c:\xampp\htdocs\.credentials\` or Infisical at
  run time and never copied into this repo, the database, logs or the page.
- Mail bodies and news text are untrusted. They are escaped on output and
  wrapped as data in every LLM prompt.

## Features

1. **New tab.** A tiny unpacked Chrome extension (`chrome-extension/`) that
   overrides the new tab and loads the dashboard. Google search box at the top
   (form GET to `https://www.google.com/search`).
2. **Ask Claude.** Text box, project picker (htdocs folders), submit. The job
   is queued; a worker runs `claude -p --output-format json` in that folder and
   the answer appears on the page when done. Last 20 Q&As kept. A button opens
   the same project in a terminal Claude Code session instead.
3. **Clients.** Live clients and CRM leads, parsed from
   `kingsburycreative/clients/_DASHBOARD.md` and `_PIPELINE.md`. Each client
   opens a panel: phase, next milestone, blocker, links (site, GA4, Search
   Console, billing, repo, client folder). Links come from a
   `config/clients.php` overrides file Rob edits.
4. **News.** RSS/Atom/JSON feeds for AI, dev, devops and security
   (`config/feeds.php`). Items are clustered by story; a story's score rises
   with the number of distinct sources covering it, Hacker News points (from
   the Algolia API) and recency. Paginated, hottest first, filter by topic.
5. **Impact on our projects.** Each story is checked against the project
   profile: first a cheap keyword pass (package names, versions, CVE IDs,
   hosts like WHC, DigitalOcean, OVH, Cloudflare), then the local model for the
   rest. Each match is marked with the project, a kind (security risk,
   upgrade/improvement, opportunity, info) and one line of why. Rob can mark a
   tag wrong; that correction becomes a future example.
6. **KC numbers.** GA4 and Search Console for KC (reuse
   this repo's `bin/collect_ga.py`, which reads only the metrics and uses the
   service account, run on a schedule locally), `status.sembr.co/api/status`, Bugsink error
   counts, and a weekly `claude -p` "what to improve / marketing ideas" note
   from those numbers.
7. **Mail digest.** Read-only IMAP (EXAMINE, never SELECT; FETCH with PEEK) for
   the WHC mailboxes and Gmail. Every 15 minutes, new messages are summarised
   by the local model into: important (needs Rob), useful (FYI), noise. Shows
   sender, subject, one-line summary. Mail bodies are never stored; kept per
   message are sender, subject, date, summary, category and the key
   account + mailbox + UIDVALIDITY + UID.
8. **Model health strip.** Which LLM backend is live, tokens per second and
   VRAM use from the last job, queue length. Auto-falls back to the next
   backend when one is down or over a latency budget. A weekly check compares
   the installed fork build (`llm/fork`, currently `prism-b10772-e8fd209`) and
   model (Ternary-Bonsai-2-27B PTQ1_0) against the newest PrismML-Eng/llama.cpp
   release and newest `prism-ml` Hugging Face model, and flags an update. It
   never downloads on its own.
9. **Extras.** Upcoming due dates from `_DASHBOARD.md`; SSL expiry for client
   domains and `mail.sembr.co` (its cert expired 2026-10-03); claude-loop status
   for projects with `.claude/work/loop/`.

## Out of scope

Writing or sending mail, any write to Stripe/GA/clients' sites, hosting this
anywhere but localhost, multi-user anything.
