---
name: KC Dashboard
test_command: c:\xampp\php\php.exe tests/run.php
test_match: (^|[;&|(]\s*)(\S*php(\.exe)?\s+)tests/run\.php\b
test_failure_start: ^FAIL\s
test_summary: ^(Tests|Result):\s
test_log: data/test-last.log
test_output_max_lines: 60
test_timeout_s: 300
verify_max_age_min: 30
ci: none
afk_log: .claude/work/log.md
scope_ignore: .claude/settings.json, .claude/settings.local.json
---

# KC Dashboard, for the shared agents

Read this, then `CLAUDE.md` and `docs/SPEC.md`.

**Done**, for the worker, means the card's done-test and the tests it added
pass (`php tests/run.php --filter <name>`), and a mutation check names which
tests failed when the new code was broken on purpose. The coordinator runs
the full suite at the gate.

**Runtime** is XAMPP's PHP 8.2 (`c:\xampp\php\php.exe`). Do not use PHP 8.3+
features. No Composer, no npm build; installing anything parks the card.

**Risky paths**, which go to the top model tier and a `security-auditor` pass:
`src/Http/` (request guard, token, origin checks), `src/Process/`, `src/Fs/`,
`ops/`, `src/Claude/` (anything that
starts `claude`), `src/Mail/` (IMAP, must stay read-only), `public/api/`,
`.htaccess`, `chrome-extension/`.

**Never**: send, flag, move or delete mail (EXAMINE and BODY.PEEK only); read a
secret value into a test, log or fixture; commit `data/`, `llm/` or anything
under `.credentials`; real network calls in tests; edit PHP with `sed` or
`node -e`.
