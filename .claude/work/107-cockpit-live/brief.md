# 107. Cockpit: the v4 page on real, live agent data (Risky)

## Objective
Rob's approved cockpit design runs inside the dashboard at
`http://127.0.0.1:8787/cockpit.php`, looking like the v4 concept, on his second
monitor full time (landscape or portrait). Its agent half is real and live:
Needs you, Working now with each agent's pipeline step, the Agents table, Agent
talk, the session and project screens, and a fuzzy Find. The daily panels
(weather, news, finance, plan limits, calendar, email, follow-ups) are drawn in
their final shape but say "Not connected yet"; cards 108 and 109 fill them.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\project.md` (risky paths, Never list)
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 107 (spec and done-test)
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\agentos\codex-hub4\index.html` (the design: the spec for the look, read all of it)
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\agentos\data-spike.md` (the transcript format, all of it)
- `D:\Dev\htdocs\_kc-dashboard\docs\AGENTOS.md` section 4.5 "What it never shows" (line ~245) and 7.7 (security)
- `D:\Dev\htdocs\_kc-dashboard\public\api\wall-stream.php`, `public\api\now.php`, `public\assets\wall.js` (live feed, API style, ticket handshake)

## Context
- Rob, 2026-11-01, approving v4: "that's friggin great ... this is it. ... Let's
  fix those issue and then wire it!" The v4 file is the spec: layout, tokens,
  type, spacing, the four monitor layouts (1920x1080, 2560x1440, 1080x1920,
  1440x2560) and the interactions. Port it, do not redesign it. He also asked
  for "a really good fuzzy search for the Find input".
- Split the single file into `public/cockpit/cockpit.css` and
  `public/cockpit/js/*.js` modules; replace its demo data with a `data.js`
  module fed by the API. Keep its DOM style (createElement, textContent,
  createElementNS for SVG).
- Trap, CSP: the page must send `Response::SECURITY_HEADERS` unchanged
  (`default-src 'self'`, `require-trusted-types-for 'script'; trusted-types 'none'`,
  `form-action 'self' https://www.google.com`). So: no innerHTML anywhere; the
  Google Fonts link will not load, so vendor Manrope (300-600) and DM Sans
  (400-600) woff2 files into `public/cockpit/fonts/` with `VENDOR.txt` (URL and
  sha256 per file; fetching those font files is the only download allowed); the
  Google search form already works under `form-action`.
- Real sources: Claude Code transcripts under
  `C:\Users\roban\.claude\projects\<encoded-dir>\<sessionId>.jsonl`, subagents in
  `<sessionId>\subagents\agent-*.jsonl` with `.meta.json` (data-spike.md has the
  fields); `NowService::now()` for live sessions (busy, idle, waiting), loops and
  tasks, already streamed every 5 s by `wall-stream.php`; loop state per project
  under `<project>\.claude\work\loop\` and `checklist.json` for the card and its
  pass (work, gate, audit, done).
- Panel mapping: Needs you = sessions in `waiting` plus parked loop cards plus
  agent questions addressed to Rob; Working now and Agents table = every live
  session and running subagent with project, model, state, current tool (last
  tool_use name and target), elapsed, pipeline step or "interactive"; Agent
  talk = Agent tool_use prompts (parent to child), SubagentHandback messages
  (child to parent) and cross-session messages, newest first, each cut to 300
  characters; Cas status line = "Cas routed it" when the parent replied or
  started another agent after it, "Needs Rob" when it is a question to the user,
  else "Cas is watching".
- Find: client-side fuzzy match (subsequence plus word-start bonus, typo
  tolerant, ranked) across projects, sessions, agents and recently touched file
  paths from the snapshot; Ctrl K focuses it; Enter opens the top hit; arrow
  keys move. No new dependency.
- Live: the page POSTs to `api/wall-stream.php` for a ticket (as wall.js does),
  listens to `event: now`, and polls `api/cockpit.php` every 10 s. Motion only
  means real activity; nothing random (`Math.random` is banned in the files).
- Indexer: incremental by byte offset per transcript, first run reads the last
  7 days only, each request spends at most 2 s indexing then answers with what
  it has. Store in `data/cockpit.sqlite`. One never-show filter (no file
  contents; nothing under `.credentials`, `.env`, `*.key`, or what the
  credential-read-blocker refuses), applied before anything is stored or
  returned. Message text goes through `Log`'s redaction and the 300-char cut.
- The Cas bar keeps its markup and the mic button; voice is card 110, not this
  one. Leave a single `voice.js` module stub with `available()` returning false.

## Start here
- Page: model `public/cockpit.php` on `public/wall.php`; a new
  `src/Cockpit/CockpitPage.php` runs `RequestGuard::checkRead`, then
  `Token::load`, and puts the token in a `<meta>` the JS reads (see
  `View::wall`, `src/View.php:56-75`). `Response::html` adds the headers.
- API: `public/api/cockpit.php` mirrors `public/api/now.php`; guard with
  `(new RequestGuard(Token::load(...)))->checkApiRead($_SERVER)` before the
  database opens (`src/View.php:235-245`).
- Config: add `transcripts_dir` to `config/running.php` beside `sessions_dir`;
  tests pass a fixture dir.
- Tests: shape of `tests/WallStreamTest.php` and `tests/NowApiTest.php`.
  `node C:\Users\roban\.claude\bin\project-test.cjs -- c:\xampp\php\php.exe tests/run.php --filter Cockpit`
  then the same with `--filter ApiGuardSweep`.

## Files you may touch
- `public/cockpit.php` (new)
- `public/api/cockpit.php` (new)
- `public/cockpit/` (new: cockpit.css, js/, fonts/ with VENDOR.txt)
- `src/Cockpit/` (new: CockpitPage.php, TranscriptIndex.php, NeverShow.php, CockpitApi.php, split as you see fit)
- `config/running.php`
- `tests/CockpitTest.php` (new; more `tests/Cockpit*Test.php` allowed)
- `tests/fixtures/cockpit/` (new: small fake transcripts, subagent files and meta, loop state; no real data)
- `tests/ApiGuardSweepTest.php` (add `cockpit.php` to `SWEEP_PAGES` and its page test)
- `.claude/work/107-cockpit-live/progress.md`, `.claude/work/107-cockpit-live/result.md`

Do not edit `.claude/work/agentos/`, `src/Http/`, `public/api/wall-stream.php`
or `src/View.php`.

## Done-test
From plan.md card 107, word for word:

`c:\xampp\php\php.exe tests/run.php --filter Cockpit` passes, and
`tests/ApiGuardSweepTest.php` passes with `cockpit.php` in its pages. The
Cockpit tests prove, against fixture transcripts: the snapshot API refuses a
request without the token; returns projects, sessions, agents with parent links,
pipeline steps and agent-talk messages from the fixtures; never returns a path
on the never-show list, file contents, or message text over 300 characters; the
page sends the unchanged `Response::SECURITY_HEADERS`; no file under
`public/cockpit/` uses `innerHTML`, `insertAdjacentHTML`, `Math.random` or an
`http(s)://` script URL; `public/cockpit/js/data.js` reads `api/cockpit.php`
and `api/wall-stream.php`.

Then the mutation check through the runner: break the never-show filter, then
the API's guard (one at a time), run the filtered tests, name the tests that
failed, restore, run green. Every run filtered; never the full suite.

## Boundaries
No commit, no push, nothing outward. The only download is the pinned font
files. Transcripts are read-only; never write under `C:\Users\roban\.claude`.
No process starts from the new code. `project.md`'s Never list applies.

## Progress
Keep `progress.md` beside this file from your first step, one line per step
that changes a file or finishes a unit of work: done, files, next, anything
half-written. Already there? You are resuming: read it and check the disk
against it first.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, which panels are real
and which say not connected, and any call made and why. Say what was not done.
