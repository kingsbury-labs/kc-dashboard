# 11 result

Files: `src/Http/Response.php` (new; triple builders, security headers incl. `frame-ancestors 'none'`, bare `{"error":"internal"}` 500, `emit()`), `src/View.php` (new; `View::page($server, $tokenPath, $log)` runs `checkRead()` before touching the token, 403 otherwise, catches any throwable into the bare 500), `src/bootstrap.php` (ini settings, error handler throwing `ErrorException` unless suppressed, exception handler emitting the bare 500), `public/index.php` (new front controller; token at `data/token`, log at `data/app.log`).

Tests added, `tests/PageTest.php`: foreign host 403 without token; good pair gives meta token, form and headers; malformed token file gives 500 with no token or `#0 `; `Response::error` with the token in the message leaks nothing. All check all five headers and the CSP parts.

Filter Page: 4 passed. Full suite: 32 passed (bootstrap is shared setup).

Mutation: dropping `X-Content-Type-Options` failed all four PageTests; putting the message in the error body failed "error response carries no message or trace". Restored, green.

Calls: the 500 path test uses a malformed token file as the in-guard-path exception. Not done: temp dirs from tests are not cleaned up; the exception handler itself is not exercised end to end (needs a web request).
