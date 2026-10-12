# 11. Responses, error handling and front controller

## Objective
Every page and API builds a `[status, headers, body]` triple through
`src/Http/Response.php`; `public/index.php` is the front controller that runs
the guard, renders the page and emits the triple. Every response carries the
security headers, and an uncaught error gives a bare JSON 500 that leaks
nothing.

## Read first
- `C:\xampp\htdocs\_kc-dashboard\CLAUDE.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\project.md`
- `C:\xampp\htdocs\_kc-dashboard\.claude\work\plan.md`, card 11 (and "Review outcome" lines naming card 11)
- `C:\xampp\htdocs\_kc-dashboard\docs\SPEC.md`, the Security section
- `C:\xampp\htdocs\_kc-dashboard\src\Http\RequestGuard.php`, `src\Http\Token.php`, `src\Log.php`, `src\bootstrap.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\RequestGuardTest.php` for house style

## Context
Headers on every response, error responses included: `Cache-Control: no-store`,
`Pragma: no-cache`, `Content-Security-Policy` with `default-src 'self'`,
`form-action 'self' https://www.google.com` and `frame-ancestors 'none'`
(the security audit of card 10 asked for it: a framed dashboard carries the
right Host, Origin and token), `X-Content-Type-Options: nosniff`,
`Referrer-Policy: no-referrer`.

Bootstrap sets `display_errors=0`, `html_errors=0`,
`zend.exception_ignore_args=1` and an exception/error handler returning JSON
500 with no message, no trace. Keep the page logic testable as a function that
takes the server array and token path and returns the triple, so tests never
need a web server or the real `data/token` (use a temp dir). The token goes in
a `<meta>` tag, HTML-escaped. The page has the Google search form
(`action="https://www.google.com/search"`, `method="get"`). A failed
`checkRead()` gives 403 and never reads or prints the token.

PHP 8.2, no Composer. Edit PHP with Edit/Write tools only, never sed or node -e.
Risky path: keep it small and obvious.

## Files you may touch
- `C:\xampp\htdocs\_kc-dashboard\src\Http\Response.php`
- `C:\xampp\htdocs\_kc-dashboard\src\bootstrap.php`
- `C:\xampp\htdocs\_kc-dashboard\src\View.php`
- `C:\xampp\htdocs\_kc-dashboard\public\index.php`
- `C:\xampp\htdocs\_kc-dashboard\tests\PageTest.php`

## Done-test
From plan.md card 11, word for word:

`php tests/run.php --filter Page` passes; foreign Host gives 403
with no token in the body; the good pair gives the meta token, the form with
`action="https://www.google.com/search"` and `method="get"`, and all five
headers; an exception thrown inside the guard path yields status 500 whose
body contains neither the token nor `#0 `.

Also assert `frame-ancestors 'none'` is in the CSP. Run tests through the
shared runner (`node C:\Users\roban\.claude\bin\project-test.cjs --filter Page`),
then the full suite through it. Mutation check through the runner too: with the
Edit tool, drop one security header and separately make the error handler
include the message; name the tests that fail; restore; run green.

## Boundaries
No commit, no push, no install, nothing outward. Do not touch the guard or
token code. `project.md`'s Never list applies.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, and any call made
and why. Say what was not done.
