# 13a. Test runner fails on a broken test file

## Objective
`tests/run.php` reports a test file that cannot load as a failure. A parse
error, a fatal error, an uncaught exception at load time, or a file that
defines no tests each print a `FAIL <file>` line and make the run exit
non-zero. Today a parse error prints `{"error":"internal"}` and exits 0,
which the shared runner records as a passing suite.

## Read first
- `C:\xampp\htdocs\_kc-dashboard\CLAUDE.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\project.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\work\plan.md`, card 13a
- `C:\xampp\htdocs\_kc-dashboard\tests\run.php`
- `C:\xampp\htdocs\_kc-dashboard\src\bootstrap.php` (its exception handler is what swallows the error: `Response::emit` prints JSON and the process exits 0)
- `C:\xampp\htdocs\_kc-dashboard\tests\WorkerTest.php` for running a PHP child process (`PHP_BINARY`, argument-array `proc_open`)

## Context
Pick the simplest approach that catches all four cases: e.g. `php -l` each
file in a child first, and/or install the runner's own exception handler and a
shutdown function after requiring bootstrap so anything escaping at load time
prints `FAIL <file>` and exits 1. A file that registers zero tests is a FAIL
too. Fixture files must live under `tests/fixtures/runner/` in one folder per
case and must not match the top-level `tests/*Test.php` glob (the runner's
`--dir` option already lets the self-test point at a fixture folder). Do not
change `src/bootstrap.php`; the fix lives in `run.php`. Keep the output
contract: `FAIL ...` lines, last line `Tests: N passed, M failed`.

PHP 8.2, no Composer. Edit PHP with Edit/Write tools only, never sed or node -e.

## Files you may touch
- `C:\xampp\htdocs\_kc-dashboard\tests\run.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\RunnerSelfTest.php` (new)
- `C:\xampp\htdocs\_kc-dashboard\tests\fixtures\runner\` (new files)

## Done-test
From plan.md card 13a, word for word:

`php tests/run.php --filter RunnerSelf` passes, and the self-test
runs `tests/run.php` as a child process against fixture folders holding (a) a
file with a syntax error, (b) a file that throws at load, (c) a file with one
failing test, (d) a clean passing file, and asserts the exit code is non-zero
for a, b and c and zero for d, with a `FAIL` line naming the file for a and b.
Mutation: make the runner swallow the load error again; the self-test fails.
After this card lands, re-run the full suite once and confirm the test count
did not drop (no earlier test file was silently skipped).

Run tests through the shared runner
(`node C:\Users\roban\.claude\bin\project-test.cjs --filter RunnerSelf`), then
the full suite through it, and say in result.md the passed count before and
after. Mutation through the runner, with the Edit tool: remove the load-error
handling; name the tests that fail; restore; run green.

## Boundaries
No commit, no push, no install, nothing outward. `project.md`'s Never list
applies.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, the full-suite
passed count before and after, and any call made and why. Say what was not done.
