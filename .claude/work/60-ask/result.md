# 60. Ask Claude core: result

Not done: the mutation check. On mutation (a) (inode check removed), the auto-mode classifier refused the test run as "Security Weaken". I put the check back straight away (SafeDir diff is now only the dot rule) and SafeDir is green again. I did not attempt (b) to (d). Rob has to allow it or run them himself.

Files: SafeDir, ProcessRunner (`extraEnv`, a case-insensitive override), executables (git), projects.php, ProjectList, Prompt, StructuredOutput, ClaudeRunner, AskHandler (with `AskApi` for both endpoints), Registry (factory entries get the worker's PDO), worker (`recoverStale`, `--stale=600`, `$_SERVER['argv'] ?? []`), run.php guard, ask.php, answers.php, 003_asks.sql.

Tests: new ProjectList, Prompt, StructuredOutput, ClaudeRunner, AskApi and BinCli tests, plus additions to SafeDir, ProcessRunner and Worker. Every done-test filter passes; full suite 106/106.

Calls:
- The volume-GUID test switched to `mklink /J`. New-Item stores `\??\\\?\Volume{..}`, which realpath cannot follow, so the old test proved nothing. I checked that realpath now returns the link itself.
- BinCli runs php-cgi against a temp copy of the app. A script missing its guard touches only the copy.
- The worker reads `$_SERVER['argv'] ?? []` because CGI has no `$argv`. Without that, a missing guard would fatal by accident instead of failing the test.

## Round two

1. Untrusted mode now passes `--tools ""`, `--restricted` and `--strict-mcp-config`. It sets no `--mcp-config` and keeps `--disallowedTools` as a second layer. ClaudeRunnerTest asserts those items, and trusted mode carries none of them. A new test sends the whole untrusted argv through a real php.exe and gets it back unchanged, empty item included. I took the flags' behaviour from your note. I did not check it, because tests never start claude.exe. Prompt.php's comment no longer claims anything about tools.
2. 003 now allows `running`. AskHandler claims the ask with `UPDATE ... WHERE id=? AND status='queued'` and needs exactly 1 row. If the ask is already running, it marks it failed and throws, so the job fails. Pruning skips running asks. Tests added: a second handle() fired while the first is inside claude starts nothing (claude still ran once), and an ask left running fails with nothing started. My mutation of the claim was refused as "Security Weaken". I restored it and did not retry, so it is owed.
3. Round one was wrong. A probe shows `cmd /c rmdir` exits 0, removes the folder, and the path reads as a junction afterwards. The swap is real. The earlier `mklink` failure had another cause. The helpers are unchanged.

Full suite 109/109.
