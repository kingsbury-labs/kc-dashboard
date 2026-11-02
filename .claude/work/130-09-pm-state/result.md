# 130-09 result (round 2)

Round 2: added `TASKS_ON_DEMAND` (`pm-state.php`) beside `TASKS_EXPECTED` in `tests/TasksScriptTest.php`. The coverage test now leaves those names out of the `bin/*.php` comparison. No other assertion changed, and `bin/register-tasks.ps1` is untouched.

Runs through the runner: `--filter TasksScript` 4 passed, `--filter PmState` 11 passed.

Round-one work stands, including its mutation check. I did not repeat the mutation check, since the round-2 change is a test-list edit only.

Not done: the full suite, which is the gate's run.
