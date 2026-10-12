# 05. Logger with redaction

## Objective
A small logger, `src/Log.php`, that appends to `data/app.log` and never writes
a registered secret: every occurrence is replaced with `[redacted]`.

## Read first
- `C:\xampp\htdocs\_kc-dashboard\CLAUDE.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\project.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\work\plan.md`, card 05
- `C:\xampp\htdocs\_kc-dashboard\docs\SPEC.md`, any logging or error-handling text
- `C:\xampp\htdocs\_kc-dashboard\src\Db.php`, `src\bootstrap.php`, `tests\DbTest.php`, `tests\run.php` for house style and how tests are written and discovered

## Context
Holds a redaction set: callers register secret values (the install token, every
value read from a credential file). Redaction applies to the message, to
strings anywhere inside nested context arrays, and to exceptions passed in
(message, and trace text if logged). The log path must be injectable so tests
write to a temp file, never the real `data/app.log`. Tests must use fake
secret strings only; never read a real credential. PHP 8.2, no Composer.
Edit PHP with the Edit/Write tools, never sed or node -e.

## Files you may touch
- `C:\xampp\htdocs\_kc-dashboard\src\Log.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\LogTest.php`

## Done-test
From plan.md card 05, word for word:

`php tests/run.php --filter Log` passes; asserts a nested array holding a
registered secret is written with `[redacted]` and the secret string is absent
from the log file, and a secret inside an exception message is also redacted.

Run it through the shared runner (`node %USERPROFILE%\.claude\bin\project-test.cjs`)
and do the mutation check through it too: break the redaction, show which tests
fail, restore. Write `result.md` beside this brief. Do not commit.
