# 12. Job queue

## Objective
A SQLite-backed job queue in `src/Jobs/Queue.php` that later cards (worker,
Ask Claude, news, mail) enqueue into and a worker claims from: enqueue, atomic
claim, complete, fail with error text, stale-claim recovery, queue length.

## Read first
- `C:\xampp\htdocs\_kc-dashboard\CLAUDE.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\project.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\work\plan.md`, cards 12 and 13
- `C:\xampp\htdocs\_kc-dashboard\docs\SPEC.md`, the parts on background jobs
- `C:\xampp\htdocs\_kc-dashboard\src\Db.php` and `src\migrations\001_init.sql`
- `C:\xampp\htdocs\_kc-dashboard\tests\DbTest.php` for house style and temp databases

## Context
Migration `002_jobs.sql` adds a `jobs` table (id, type, payload JSON, status
`queued|running|done|failed`, result, error, attempts, created/claimed/finished
timestamps). Claim must be atomic across processes: one statement or an
`IMMEDIATE` transaction so two connections claiming the same single job give
exactly one winner. Stale recovery: a `running` job claimed longer ago than a
timeout goes back to `queued`. Take the clock as a parameter or injectable so
tests do not sleep. Card 13 builds the worker on this, so keep the API small
and plain. Tests use a temp database file, never `data/`.

PHP 8.2, no Composer. Edit PHP with Edit/Write tools only, never sed or node -e.

## Files you may touch
- `C:\xampp\htdocs\_kc-dashboard\src\Jobs\Queue.php`
- `C:\xampp\htdocs\_kc-dashboard\src\migrations\002_jobs.sql`
- `C:\xampp\htdocs\_kc-dashboard\tests\QueueTest.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\DbTest.php`, only the "migrating twice
  records one migration row" assertion: it hardcodes 1, so make it count the
  `.sql` files in `src/migrations` (still proving a second migrate adds no rows).

## Done-test
From plan.md card 12, word for word:

`php tests/run.php --filter Queue` passes; two claims on one job
give one winner, a failed job stores its error, a claim older than the timeout
returns to queued.

The two claims must come from two separate PDO connections to the same file.
Run tests through the shared runner
(`node C:\Users\roban\.claude\bin\project-test.cjs --filter Queue`), then the
full suite through it. Mutation check through the runner: with the Edit tool,
make claim non-atomic (drop the status condition) and separately make stale
recovery ignore the timeout; name the tests that fail; restore; run green.

## Boundaries
No commit, no push, no install, nothing outward. `project.md`'s Never list
applies.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, and any call made
and why. Say what was not done.
