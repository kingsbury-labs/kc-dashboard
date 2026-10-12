
## 2026-10-12 card 05, Logger with redaction: parked
Worker wrote src/Log.php and tests/LogTest.php; the filtered tests pass. The mutation check (break redact(), rerun --filter Log) was refused by the permission classifier as a "security test removal", so the card could not reach the gate. Not routed around. Code is on branch wip/05-log. Needs Rob: allow that mutation run (or run it himself), then gate the card from wip/05-log.

## 2026-10-12 card 05 reopened
History was squashed into one commit for the public repo (kingsbury-labs/kc-dashboard), so the old start_sha and wip/05-log no longer exist on origin. The entry is removed so the card is briefed fresh. .claude/loop-settings.json now allows the project test command and edits under src/, tests/, public/ and bin/, which is what the classifier refused.

## 2026-10-12 card 05, Logger with redaction: parked again
Worker wrote src/Log.php and tests/LogTest.php but the permission classifier refused every test run, including the plain `project-test.cjs --filter Log` (first try also used sed on PHP for the mutation, against project.md). No green run, no mutation check, no result.md. Not routed around. Code is on wip/05-log (cut from e30f67b). Needs Rob: let the implementer run `node C:\Users\roban\.claude\bin\project-test.cjs` headless, then re-brief from wip/05-log. Every later card needs test runs too, so the loop is blocked until then.

## 2026-10-12 card 05 reopened again
Rob approved allowing the shared runner (node project-test.cjs, every path spelling) in loop-settings.json. Resume from the code on wip/05-log; mutate with the Edit tool, never sed.

## 2026-10-12 card 05, Logger with redaction: gated and committed
Resumed from wip/05-log; filtered tests and mutation check ran through the runner, full suite green.

## 2026-10-12 card 10, Request guard and install token: at the gate
Scope ok with mutation evidence, diff read, security-auditor (Opus) found nothing in the guard or token. Token header is `X-KC-Token` (`RequestGuard::TOKEN_HEADER`). For card 11: add `frame-ancestors 'none'` to the CSP, since a framed dashboard carries the right Host, Origin and token (clickjacking). Token file left empty by a crash mid-create makes every load throw until deleted; fails closed.

## 2026-10-12 card 11, Responses, error handling and front controller: at the gate
Scope ok with mutation evidence, diff read, security-auditor (Opus) found no concrete attack. Follow-ups it raised, not blocking: fatal errors skip the handlers (add a shutdown function), `Response::emit` should send no body once headers are already sent, the 403 test should prove the token file is never created, the bootstrap exception handler is untested, caught exceptions are not logged. Needs Rob soon: until card 07 installs the vhost, port 80 serves the whole project folder, so any site Rob visits can make Apache run `tests/run.php`; no token leak, but it executes code.
