# 14. Safe directory check (junctions)

## Objective
`src/Fs/SafeDir.php` with `isDirectChild(string $root, string $name): bool`,
which every launch cwd will go through: true only when `$name` names a real
folder that sits directly inside `$root` and does not lead anywhere else.

## Read first
- `C:\xampp\htdocs\_kc-dashboard\CLAUDE.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\project.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\work\plan.md`, card 14
- `C:\xampp\htdocs\_kc-dashboard\docs\SPEC.md`, the Security section
- `C:\xampp\htdocs\_kc-dashboard\tests\QueueTest.php` for house style and temp folders

## Context
Rule from the plan: true only when `realpath(root . '\' . name)` equals
`realpath(root) . '\' . name` exactly. No `is_link` (false for junctions on
PHP 8.2.12). Reject before realpath: empty name, `.`, `..`, any `/` or `\`,
`:` (drive or stream), NUL. A missing folder, a file rather than a folder, or
a root that does not resolve is false. Compare case-sensitively as the plan
says; note in result.md if that rejects a real folder due to case and why.

The test creates the junction for real in a temp root, pointing at a second
temp folder outside it: `cmd /c mklink /J` started with `proc_open` in
argument-array form (no shell string) is fine, as is PowerShell
`New-Item -ItemType Junction`. If creating a junction fails, the test fails;
it never skips. Remove junctions with `rmdir` (never recursive delete through
a junction) and clean every temp folder.

PHP 8.2, no Composer. Edit PHP with Edit/Write tools only, never sed or node -e.

## Files you may touch
- `C:\xampp\htdocs\_kc-dashboard\src\Fs\SafeDir.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\SafeDirTest.php`

## Done-test
From plan.md card 14, word for word:

`php tests/run.php --filter SafeDir` passes; in a temp root, a
real junction made with `New-Item -ItemType Junction` pointing outside is
rejected, a plain folder accepted, `..` and `a\b` rejected.

Run tests through the shared runner
(`node C:\Users\roban\.claude\bin\project-test.cjs --filter SafeDir`), then the
full suite through it. Mutation check through the runner: with the Edit tool,
make `isDirectChild` return true whenever realpath succeeds (dropping the
equality check); name the tests that fail; restore; run green.

## Boundaries
No commit, no push, no install, nothing outward. `project.md`'s Never list
applies.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, and any call made
and why. Say what was not done.

## Round three (resumed after card 13a)
The round-two code from wip/14-safedir is already in the working tree. Both
files fail `php -l`: string literals that should be a single backslash
(`'\'` in PHP source) lost their escaping, e.g. SafeDir.php line 11/28 and
SafeDirTest.php line 100. Fix every such literal with the Edit or Write tool
(never sed), confirm `c:\xampp\php\php.exe -l` passes on both files, then run
`--filter SafeDir` and the full suite through the shared runner. Mutation
checks through the runner: (1) drop the realpath equality check, (2) drop the
stat/lstat ino/dev comparison; name the tests each one fails, restore, run
green. Replace result.md with a fresh one covering all three rounds.
