# 60. Ask Claude core

## Objective
The server side of Ask Claude works end to end with fakes: a POST to
`public/api/ask.php` names a project from a safe listing, a job is queued,
the worker runs `claude -p --model sonnet` through ProcessRunner in that
project's folder, and `public/api/answers.php` returns the last 20 answers.
Untrusted data always goes through `Prompt` and runs in an empty temp folder
with tools disabled. Every `bin/*.php` and `tests/run.php` refuse to run
outside the CLI.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 60 (the whole card is the spec for this task)
- `D:\Dev\htdocs\_kc-dashboard\docs\SPEC.md`, Security section
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\log.md`, the card 14 and card 15 entries (follow-ups this card picks up)
- `D:\Dev\htdocs\_kc-dashboard\src\Fs\SafeDir.php`, `src\Process\ProcessRunner.php`, `src\Jobs\Queue.php`, `src\Jobs\Registry.php`, `src\Http\RequestGuard.php`, `src\Http\Response.php`, `src\bootstrap.php`, `bin\worker.php`, `tests\run.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\SafeDirTest.php`, `tests\ProcessRunnerTest.php`, `tests\WorkerTest.php`, `tests\RequestGuardTest.php`, `tests\Fakes\` (style and fakes to reuse)

## Context
- Real paths on this machine: git is `C:\Program Files\Git\cmd\git.exe`;
  php-cgi is `c:\xampp\php\php-cgi.exe`; `claude.exe` is already in
  `config/executables.php`. kc-finance exists at `C:\Users\roban\kc-finance`.
- Tests never start `claude.exe`, never touch the network, never read
  kc-finance's files. Use `FakeProcessRunner` for ClaudeRunner and AskHandler;
  ProjectList and SafeDir tests build temp roots with real junctions
  (`mklink /J`, cleaned up after).
- BinCliTest may start real `php-cgi.exe` through ProcessRunner (or
  proc_open in the test) against each `bin/*.php` and `tests/run.php`; assert
  non-zero exit and that nothing ran (no job claimed, no output beyond the
  refusal).
- PHP 8.2 only. Edit PHP with the Edit/Write tools only, never sed or node -e.

## Files you may touch
- `D:\Dev\htdocs\_kc-dashboard\src\Projects\ProjectList.php`
- `D:\Dev\htdocs\_kc-dashboard\config\projects.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Fs\SafeDir.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Process\ProcessRunner.php`
- `D:\Dev\htdocs\_kc-dashboard\config\executables.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Llm\Prompt.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Llm\StructuredOutput.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Claude\ClaudeRunner.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Jobs\AskHandler.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Jobs\Registry.php`
- `D:\Dev\htdocs\_kc-dashboard\bin\worker.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\run.php`
- `D:\Dev\htdocs\_kc-dashboard\public\api\ask.php`
- `D:\Dev\htdocs\_kc-dashboard\public\api\answers.php`
- `D:\Dev\htdocs\_kc-dashboard\src\migrations\003_asks.sql`
- `D:\Dev\htdocs\_kc-dashboard\tests\ProjectListTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\SafeDirTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\ProcessRunnerTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\PromptTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\StructuredOutputTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\ClaudeRunnerTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\AskApiTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\WorkerTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\BinCliTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\Fakes\FakeProcessRunner.php`

`Queue::recoverStale` already exists; the worker only needs to call it.

## Done-test
From plan.md card 60, word for word: the "Done-test:" paragraph at the end
of card 60 (ProjectListTest through BinCliTest). Every filter named there
passes.

Then the full suite passes through the runner
(`node D:/Dev/htdocs/_kc-dashboard/.claude/bin/project-test.cjs`, forward
slashes in bash; `--filter <name>` for one test). Mutation check through
the runner, with the Edit tool, one at a time, naming the failing tests
each time and restoring after: (a) remove the stat/lstat inode check in
SafeDir; (b) drop `--disallowedTools` from ClaudeRunner's untrusted mode;
(c) remove the CLI guard from `bin/worker.php`; (d) skip the SafeDir recheck
in AskHandler at launch. Then run green.

## Boundaries
No commit, no push, no install, nothing outward. Never start `claude.exe`,
`wt.exe` or `llama-server.exe`, and never read anything under
`C:\Users\roban\kc-finance` or `.credentials`. `project.md`'s Never list
applies. A file not in the list above is out of scope; if one must change,
stop and say so in result.md.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, and any call made
and why. Say what was not done.
