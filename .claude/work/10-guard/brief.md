# 10. Request guard and install token

## Objective
`src/Http/RequestGuard.php` decides whether a request may read or write, using
only exact byte comparisons against the loopback address and the two allowed
Host values. `src/Http/Token.php` creates the per-install token once and
registers it with the logger's redaction.

## Read first
- `C:\xampp\htdocs\_kc-dashboard\CLAUDE.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\project.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\work\plan.md`, card 10 (and "Review outcome" lines naming card 10)
- `C:\xampp\htdocs\_kc-dashboard\docs\SPEC.md`, the Security section
- `C:\xampp\htdocs\_kc-dashboard\src\Log.php` (`addSecret()`), `tests\LogTest.php`, `tests\run.php` for house style and test discovery

## Context
`checkRead(array $server)`: `REMOTE_ADDR` is exactly `127.0.0.1` or `::1`;
`HTTP_HOST` byte-equal to `127.0.0.1:8787` or `localhost:8787`. `SERVER_NAME`
and every forwarded header (`X-Forwarded-*`, `Forwarded`) are ignored.
`checkWrite(array $server)` adds: method POST; token header compared with
`hash_equals` (a short or missing token rejects, never errors); `HTTP_ORIGIN`
byte-equal to `http://` plus the Host actually sent. Take the server array as
a parameter so tests pass arrays; no reliance on globals.

Token: 32 random bytes as hex, file path injectable (tests use a temp dir,
never the real `data/token`), created with `fopen(..., 'x')` so a race never
overwrites; an existing file is read, not rewritten. The value is registered
with a `Log` via `addSecret()`. Never print the token in an exception message.

PHP 8.2, no Composer. Edit PHP with Edit/Write tools only, never sed or node -e.
This is a risky path: keep it small and obvious.

## Files you may touch
- `C:\xampp\htdocs\_kc-dashboard\src\Http\RequestGuard.php`
- `C:\xampp\htdocs\_kc-dashboard\src\Http\Token.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\RequestGuardTest.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\TokenTest.php`

## Done-test
From plan.md card 10, word for word:

`php tests/run.php --filter RequestGuard` and `--filter Token`
pass. Every rejection case sets `SERVER_NAME => 'localhost'`. Rejects:
`REMOTE_ADDR` `192.168.1.5`, Host `localhost`, `LOCALHOST:8787`,
`localhost.:8787`, `localhost:8787,evil.com`, `[::1]:8787`, `evil.com`; on
write: GET, no token, wrong token, short token, Origin `null`, missing,
`chrome-extension://abc`, `http://localhost:8787.evil.com`,
`http://user@localhost:8787`, and Origin `http://localhost:8787` with Host
`127.0.0.1:8787`. Accepts both exact pairs. TokenTest: one file, second call
returns the same value, mtime unchanged.

Run tests through the shared runner (`node C:\Users\roban\.claude\bin\project-test.cjs --filter RequestGuard`, then `--filter Token`)
and do the mutation check through it too: break the Host comparison and the
token check with the Edit tool, show which tests fail, restore. Write
`result.md` beside this brief. Do not commit.
