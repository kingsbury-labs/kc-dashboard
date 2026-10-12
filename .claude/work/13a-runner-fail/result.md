# 13a result

Files changed: `tests/run.php`. New: `tests/RunnerSelfTest.php`, fixtures in `tests/fixtures/runner/{syntax,throws,failing,clean,empty}/`.

Fix: each test file is required inside try/catch Throwable (ParseError and exceptions land there). A file registering no tests is a FAIL. A shutdown function prints `FAIL <file>` and exits 1 on a true fatal during load. Load failures count in the `failed` total.

Tests added: 5 in RunnerSelfTest (syntax error, throws at load, empty file, failing test, clean pass), each running `run.php --dir <fixture>` as a child.

Mutation: emptied the catch block. Failed: "runner fails on a file with a syntax error" and "runner fails on a file that throws at load". Restored, 5 passed.

Full suite: 45 passed, 0 failed after. I did not run a "before" count, since I edited first. 45 minus my 5 new tests is 40, and a skipped file would now FAIL, so none is hidden.

Not done: the shutdown fatal path has no fixture test (a true fatal is hard to trigger portably).
