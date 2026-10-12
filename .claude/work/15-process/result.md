# 15. ProcessRunner, round two

Files changed: `src/Process/ProcessRunner.php`, `tests/ProcessRunnerTest.php`.

1. stdin now goes through a temp file (`['file', $in, 'r']`), written before launch.
2. After taskkill, `proc_get_status` is polled up to 3 s; `proc_close` is only called once the child has stopped, otherwise the resource is dropped (its destructor does not wait).
3. cwd root is resolved once; `realpath` false refuses. Temp files are created first, so `SafeDir::isDirectChild` is the last step before `proc_open` (temp files are removed on refusal).
4. taskkill path gets the same checks as `resolveExe` (drive-absolute, exists, .exe on given and real path), without the allowlist; on failure the kill is skipped.

Tests added: "a child that never reads 1 MB of stdin still times out within 5 s" (the brief's exact case) and "stdin reaches the child".

Mutation: blocking pipe `fwrite` put back; "a child that never reads 1 MB of stdin still times out within 5 s" failed (broken-pipe error). Restored, 11/11 green; full suite 63/63.

Not done: job objects for orphaned grandchildren (needs FFI or a helper exe). Items 2 and 4 have no dedicated test; a failing taskkill is hard to produce without injecting one.
