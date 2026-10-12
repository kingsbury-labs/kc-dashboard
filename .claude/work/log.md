
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

## 2026-10-12 card 12, Job queue: at the gate
Scope ok with mutation evidence, diff read; not a risky path, no audit. Round two added tests/DbTest.php to the brief: its migration-count assertion hardcoded 1 and now counts the migration files.

## 2026-10-12 card 13, Worker and job registry: at the gate
Scope ok with mutation evidence, diff read; not a risky path, no audit. Worker does not call recoverStale yet and the default registry is empty; feature cards fill it.

## 2026-10-12 card 14, Safe directory check (junctions): parked
Round one passed scope, but the Opus security-auditor found two real bypasses, both confirmed on PHP 8.2 by round two: a junction to a `\??\Volume{guid}` path is left unresolved by realpath and passes, and the realpath cache lets a folder swapped for a junction pass for up to two minutes. Round two's fix (clearstatcache(true), reject when stat and lstat inodes differ) is right in substance, but its files lost every `'\'` to `'\'`, so SafeDir.php and SafeDirTest.php do not parse. Two top-tier attempts, so parked; code on wip/14-safedir. Fix the backslashes, then run the mutation checks named in its result.md.

Blocks the loop: a parse error in any test file makes `tests/run.php` print `{"error":"internal"}` and exit 0, so the runner records a finished, passing run with no summary. Card 11's bootstrap exception handler swallows fatals in the CLI. Until run.php exits non-zero on that, a green suite proves nothing. Needs Rob to approve fixing that first, as its own card.

## 2026-10-12 card 13a added
Rob approved: card 13a (runner fails on a broken test file) goes next, before anything else. When 13a passes, unpark card 14 and resume it from wip/14-safedir.

## 2026-10-12 card 13a, Test runner fails on a broken test file: at the gate
Scope ok with mutation evidence, diff read; not a risky path, no audit. Full suite was 40 before (card 13), 45 after with the 5 new self-tests, so no file was silently skipped. The true-fatal shutdown path has no test.

## 2026-10-12 card 14, Safe directory check (junctions): unparked, at the gate
Round three (Opus) restored the lost backslashes; both files parse, filtered and full suite green through the runner, scope ok with mutation evidence. Opus security-auditor found no concrete bypass. Kept the stat/lstat inode check: it is the only guard against a junction to `\??\Volume{guid}` (round two reproduced that bypass), but no test fails when it is removed, because the volume-GUID test builds a junction realpath cannot follow. Follow-ups for a later card: rebuild that test so dropping the inode check fails it; move the dot-folder rule into SafeDir; ProcessRunner (card 15) takes its root from config and rechecks before launch; card 25's `wt.exe -d` cannot be rechecked.

## 2026-10-12 card 15, ProcessRunner: at the gate
Round one passed scope; the Opus security-auditor found no way to run an unlisted program, inject through cmd, escape the cwd root or leak env, but a blocking stdin write before the timeout let a stalled child hang `run()` forever. Round two (Opus) moved stdin to a temp file with a test that fails when the blocking write returns, bounded the wait after taskkill, resolved the cwd root once and refuses when it does not exist. Follow-ups, not blocking: orphaned grandchildren survive `taskkill /T` (needs a job object, FFI or a helper exe); if taskkill fails, `unset($proc)` probably still waits on Windows (a guess, not checked); `wt.exe` is a WindowsApps alias that realpath may not resolve, so card 25 may be refused.
