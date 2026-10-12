# 01. Test runner and SQLite bootstrap

## Objective
The project has a plain-PHP test runner at `tests/run.php` and a SQLite
database layer (`src/Db.php`) with migrations, loaded through
`src/bootstrap.php`. Every later card writes its tests for this runner, so its
output format is a contract.

## Read first
- `C:\xampp\htdocs\_kc-dashboard\CLAUDE.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\project.md` (note `test_failure_start: ^FAIL\s` and `test_summary: ^(Tests|Result):\s`; the shared runner parses those)
- `C:\xampp\htdocs\_kc-dashboard\.claude\work\plan.md`, card 01 only
- `C:\xampp\htdocs\_kc-dashboard\docs\SPEC.md`, skim for the data folder and stack

## Context
The repo has no PHP code yet; this card creates the first. PHP is XAMPP's 8.2
at `c:\xampp\php\php.exe`: no 8.3+ features, no Composer. The fixtures under
`tests/fixtures/runner/` must not be discovered by the default run (only
`tests/*Test.php`, not subfolders), or the deliberately failing fixture turns
the real suite red.

## Files you may touch
- `C:\xampp\htdocs\_kc-dashboard\tests\run.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\RunnerTest.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\fixtures\runner\PassingTest.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\fixtures\runner\FailingTest.php`
- `C:\xampp\htdocs\_kc-dashboard\src\bootstrap.php`
- `C:\xampp\htdocs\_kc-dashboard\src\Db.php`
- `C:\xampp\htdocs\_kc-dashboard\src\migrations\001_init.sql`
- `C:\xampp\htdocs\_kc-dashboard\tests\DbTest.php`

## Done-test
From plan.md card 01, word for word:

Runner discovers `tests/*Test.php`, `--filter <substring>`, `--dir`, prints
`FAIL <name>` per failure and last line `Tests: N passed, M failed`, exits 1 on
any failure. Db uses PDO SQLite, path injectable, migrations recorded once, and
runs `PRAGMA journal_mode=WAL` and `PRAGMA busy_timeout=5000` on open.

Done-test: `c:\xampp\php\php.exe tests/run.php --filter Runner` and
`--filter Db` pass. `tests/RunnerTest.php` runs the runner on
`tests/fixtures/runner` and asserts exit 1, one `FAIL` line naming the failing
test, `Tests: 1 passed, 1 failed`, and `--filter Passing` exits 0.
`tests/DbTest.php` asserts migrating a temp file twice records one migration
row, and reading back the pragmas gives `wal` and `5000`.

Then the full suite passes through the runner
(`node %USERPROFILE%\.claude\bin\project-test.cjs`), and the mutation check,
run through the runner too so it is on record: break the new code on purpose,
run, name the tests that failed, restore, run again green.

## Boundaries
No commit, no push, no install, nothing outward. Temp databases go in the
system temp folder and are deleted; never write into `data/`. Follow the
Never list in `.claude/project.md`.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, and any call made
and why. Say what was not done.
