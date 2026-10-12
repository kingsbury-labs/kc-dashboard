# 01 result

Files created (all on the brief's list): tests/run.php, tests/RunnerTest.php, tests/DbTest.php, tests/fixtures/runner/PassingTest.php, FailingTest.php, src/bootstrap.php, src/Db.php, src/migrations/001_init.sql.

Tests added: 2 in RunnerTest (fixture run exits 1 with one FAIL line and "Tests: 1 passed, 1 failed"; --filter Passing exits 0), 2 in DbTest (double migrate gives one row; pragmas read back wal and 5000).

Full suite through the runner: 4 passed, 0 failed.

Mutation: removed the WAL pragma and forced the runner to exit 0. Failed: DbTest "open sets WAL and busy_timeout", RunnerTest "fixture dir: exit 1...". Restored, green again (4 passed).

Calls made: tests register with test(name, fn) plus assert_true/assert_same, defined in run.php. --filter matches substring of "FileName: test name". FAIL line is "FAIL <File>: <test>" with the message indented below. Migrations table is `migrations`.

Not done: nothing outstanding.
