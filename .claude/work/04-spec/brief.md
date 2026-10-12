# 04. Spec update for the review decisions

## Objective
`docs/SPEC.md` records the decisions made after the Codex and Fable reviews, so
every later card builds against the spec rather than the plan's notes.

## Read first
- `C:\xampp\htdocs\_kc-dashboard\CLAUDE.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\project.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\work\plan.md`, card 04, the "Review outcome" and "Flags" sections
- `C:\xampp\htdocs\_kc-dashboard\.claude\work\reviews\codex.md` and `fable.md` where a decision needs its detail (llama-server flags, port 8081, the `json_schema` measurement)
- `C:\xampp\htdocs\_kc-dashboard\docs\SPEC.md`, all of it

## Context
Doc card, but the done-test becomes a real test so the mutation check runs
through the shared runner: `tests/SpecTest.php` reads `docs/SPEC.md` and
asserts each required string is present and `.htaccess denies` is absent. Edit
the spec in place, in its existing voice and sections; replace superseded
text rather than appending contradictions. Do not invent facts the plan and
reviews do not give; if one is missing, write what is known and say so in
result.md.

## Files you may touch
- `C:\xampp\htdocs\_kc-dashboard\docs\SPEC.md`
- `C:\xampp\htdocs\_kc-dashboard\tests\SpecTest.php`

## Done-test
From plan.md card 04, word for word:

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

`c:\xampp\php\php.exe tests/run.php --filter Spec` passes. Then the full suite
passes through the runner (`node %USERPROFILE%\.claude\bin\project-test.cjs`),
and the mutation check, run through the runner so it is on record: remove one
required string from the spec, run, name the test that failed, restore, run
again green.

## Boundaries
No commit, no push, no install, nothing outward. Follow the Never list in
`.claude/project.md`.

## Report
Write `result.md` beside this file, under 200 words: files changed, test
added, the mutation result naming the test that failed, any call made and
why. Say what was not done.
