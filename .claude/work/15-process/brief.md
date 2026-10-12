# 15. ProcessRunner

## Objective
`src/Process/ProcessRunner.php` is the one way the dashboard starts a process:
only absolute paths to allowlisted `.exe` files, argv-array `proc_open` with
`bypass_shell`, an explicit allowlisted environment, an optional cwd that
`SafeDir` rechecks immediately before launch, and a timeout that kills the
whole process tree. A `FakeProcessRunner` lets later cards test without
starting anything.

## Read first
- `C:\xampp\htdocs\_kc-dashboard\CLAUDE.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\project.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\work\plan.md`, card 15
- `C:\xampp\htdocs\_kc-dashboard\docs\SPEC.md`, the Security section and "Decisions already made"
- `C:\xampp\htdocs\_kc-dashboard\src\Fs\SafeDir.php` and `tests\SafeDirTest.php` (junction creation and cleanup in tests)
- `C:\xampp\htdocs\_kc-dashboard\src\Log.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\run.php`

## Context
- cwd is given as a SafeDir root plus a folder name, never a free path; the
  runner calls `SafeDir::isDirectChild` itself right before `proc_open`, and
  takes the root from its own config/constructor, not from the caller's request.
- `config/executables.php` returns a name => absolute path map. Use real paths
  on this machine where they exist (find them, e.g. `where.exe`, without
  running claude); an entry may point at a file that is absent, and the runner
  then refuses it. Tests should inject their own allowlist (e.g. `c:\xampp\php\php.exe`
  plus a temp `.bat`/`.cmd`) rather than depend on the real config.
- Extension check is case-insensitive and on the resolved real path, so
  `X.EXE`, trailing dots/spaces, `::$DATA` and 8.3 short names cannot sneak a
  `.bat` through. Refusals throw before `proc_open`.
- Never put a secret into a test or log. PHP 8.2 only. Edit PHP with Edit/Write tools only, never sed or node -e.

## Files you may touch
- `C:\xampp\htdocs\_kc-dashboard\src\Process\ProcessRunner.php`
- `C:\xampp\htdocs\_kc-dashboard\config\executables.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\Fakes\FakeProcessRunner.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\ProcessRunnerTest.php`

## Done-test
From plan.md card 15, word for word:

`php tests/run.php --filter ProcessRunner` passes; real `php.exe`
echoing `json_encode($argv)` round-trips arguments containing spaces, `"`,
`&`, `|`, `%PATH%`, `^` and `..\..` unchanged; a temp `.bat` that would write a
marker is refused and the marker never appears; `.cmd`, a relative path and
an unlisted `.exe` are refused; a cwd swapped for a junction after
validation is refused; a sleeping child is killed within 5 s of a 1 s
timeout; the child sees no variable outside the allowlist.

Run tests through the shared runner
(`node C:\Users\roban\.claude\bin\project-test.cjs --filter ProcessRunner`),
then the full suite through it. Mutation check through the runner: with the
Edit tool, (a) remove the extension check, (b) remove the SafeDir recheck
before `proc_open`; each time run, name the tests that failed, restore; run green.

## Boundaries
No commit, no push, no install, nothing outward. Never start `claude.exe`,
`wt.exe` or `llama-server.exe` in a test. `project.md`'s Never list applies.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, and any call made
and why. Say what was not done.

## Round two (security-auditor findings)
Round one's code is in the working tree; fix these, keep everything else.
1. stdin is written with a blocking `fwrite` before the timeout clock starts,
   so `run([php,'-r','sleep(30);'], null, 1, str_repeat('x', 1<<20))` takes
   ~30 s and a child that never reads hangs forever. Pass stdin through a temp
   file (`['file', $in, 'r']`) like stdout. Add that exact case as a test: it
   must return within 5 s with timedOut true.
2. After taskkill, `proc_close` can wait forever if the kill failed. Poll
   `proc_get_status` to a second short deadline before `proc_close`.
3. cwd: reuse one resolved root; if `realpath($this->cwdRoot)` is false,
   refuse. Create temp files before the SafeDir check so the check stays the
   last thing before `proc_open`.
4. Start taskkill through `resolveExe`-style checks (absolute, exists, .exe).
Not in scope: job objects for orphaned grandchildren (needs FFI or a helper
exe); note it in result.md. Mutation check again for item 1: put the blocking
write back, run, name the failing test, restore.
