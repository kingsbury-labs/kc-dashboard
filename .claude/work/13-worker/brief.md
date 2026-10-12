# 13. Worker and job registry

## Objective
A registry mapping job type to handler class, and `bin/worker.php`, a CLI
process that claims jobs from the card 12 queue, runs their handlers, records
done or failed, and exits when its time budget is spent.

## Read first
- `C:\xampp\htdocs\_kc-dashboard\CLAUDE.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\project.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\work\plan.md`, cards 12 and 13
- `C:\xampp\htdocs\_kc-dashboard\docs\SPEC.md`, the parts on background jobs
- `C:\xampp\htdocs\_kc-dashboard\src\Jobs\Queue.php`, `src\Db.php`, `src\bootstrap.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\QueueTest.php` for house style and temp databases

## Context
`src/Jobs/Registry.php`: maps type string to handler class; feature cards add
one line each, so keep the default map a plain array. A handler takes the
decoded payload and returns a result (string or JSON-encodable). An unknown
type, or a handler that throws, marks the job `failed` with the error text; it
is never re-queued forever. The worker checks its time budget between jobs
(never kills a running handler) and exits when the budget is spent or the
queue is empty. It must accept the database path and a registry override
(e.g. CLI options or env) so the test can run it as a real process against a
temp database with a test handler; never touch `data/` in tests. Start the
child process with `PHP_BINARY`, argument array form of `proc_open` (no shell
string). Errors go through `src/Log.php` where it fits.

PHP 8.2, no Composer. Edit PHP with Edit/Write tools only, never sed or node -e.

## Files you may touch
- `C:\xampp\htdocs\_kc-dashboard\src\Jobs\Registry.php`
- `C:\xampp\htdocs\_kc-dashboard\bin\worker.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\WorkerTest.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\fixtures\worker\` (new files: a test handler and test registry)

## Done-test
From plan.md card 13, word for word:

`php tests/run.php --filter Worker` passes; enqueues a test-type
job in a temp database, runs `bin/worker.php` as a real process with that
database and a test registry, and asserts the job is `done` with the
handler's output; an unknown type ends `failed`.

Run tests through the shared runner
(`node C:\Users\roban\.claude\bin\project-test.cjs --filter Worker`), then the
full suite through it. Mutation check through the runner: with the Edit tool,
make the worker skip calling `complete` and separately make an unknown type
get re-queued instead of failed; name the tests that fail; restore; run green.

## Boundaries
No commit, no push, no install, nothing outward. `project.md`'s Never list
applies.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, and any call made
and why. Say what was not done.
