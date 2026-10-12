# 10. Result

Files: `src/Http/RequestGuard.php`, `src/Http/Token.php`, `tests/RequestGuardTest.php`, `tests/TokenTest.php`.

Tests added: RequestGuardTest (accepts both exact pairs for both addresses; rejects every listed address/Host case, with forwarded headers set to loopback values; every listed write rejection plus empty token), TokenTest (one file, same value, mtime unchanged, value redacted by the Log).

Both filters pass through the runner (3 and 2 passed).

Mutation: Host compared after `strtolower` and token check reduced to `is_string` failed "rejects bad address and hosts" (LOCALHOST:8787) and "write rejects method, token and origin cases" (wrong token). Restored, green again.

Calls made:
- Token header name was not specified anywhere; I chose `X-KC-Token` (`RequestGuard::TOKEN_HEADER = 'HTTP_X_KC_TOKEN'`). Later cards should use that constant.
- The guard takes the expected token in its constructor; `checkRead`/`checkWrite` take only the server array.
- `Token::load($path, $log)` throws on a malformed file; the message names the path, never the value.
- `src/Http/` was not wired into `bootstrap.php` (not in my file list); tests `require_once` directly.
