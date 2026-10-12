# 14. Safe directory check: result (rounds one to three)

Files: `src/Fs/SafeDir.php`, `tests/SafeDirTest.php`. Both now pass `php -l`.

Round three fixes: the single-backslash literals in both files restored with Edit (SafeDir lines 21 and 31; test volume-GUID prefix, `rtrim`, `\vol`, `\swap`). The volume-GUID test then failed because PHP's `is_dir` is false for a junction to a `\\?\Volume{...}` path (checked by hand), so the helper now confirms the junction with `lstat`.

Tests (7): plain folder accepted; wrong case rejected (added this round); junction outside rejected; dot names, separators, drives, streams, NUL rejected; missing, file, bad root rejected; volume-GUID junction rejected; folder swapped for a junction rejected. `--filter SafeDir` 7/7, full suite 52/52.

Mutation 1 (drop realpath equality): at first nothing failed, since ino/dev caught every junction. Added the wrong-case test; it then failed. Restored, green.

Mutation 2 (drop ino/dev comparison): nothing fails. Not done: I found no case where only ino/dev rejects. The volume-GUID junction it was written for already fails at `realpath` (returns false on PHP 8.2), and every other junction fails the equality. It is untested defence in depth; the coordinator should decide whether to keep it or remove it.

Case: a correctly named folder always passes; only a differently cased name is rejected, by design.
