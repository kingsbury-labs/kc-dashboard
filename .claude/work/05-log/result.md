# 05 result

Files: src/Log.php, tests/LogTest.php (both from the earlier attempt; reviewed, no edits needed).

Tests (3, all fake secrets, temp log path):
- nested array secret redacted and absent from file
- secret in exception message and trace redacted (direct and as context object)
- newlines cannot forge a second entry

Mutation: changed redact() to replace `$secret . 'x'`. Failed: "nested array secret is redacted and absent from the file" and "secret in exception message and trace is redacted" (secret leaked). Restored, rerun: 3 passed.

Call made: kept the earlier code as is; it covers message, nested context, keys, exceptions and traces, longest secret first.

Not done: full suite (coordinator's gate). No commit.
