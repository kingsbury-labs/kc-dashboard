# Result 12 (round 2)

Files: `src/Jobs/Queue.php`, `src/migrations/002_jobs.sql`, `tests/QueueTest.php` (round 1, untouched), `tests/DbTest.php` (round 2).

Round 2: DbTest "migrating twice records one migration row" now asserts the row count equals the number of `src/migrations/*.sql` files, so it still proves migrating twice adds no duplicates, and survives future migrations.

Full suite through the runner: 37 passed, 0 failed.

Mutation (round 1, still valid): claim without status condition failed 4 Queue tests; stale recovery ignoring timeout failed 1. Both restored, green. No new mutation run for the DbTest edit (assertion-only change).

Not done: nothing outstanding.
