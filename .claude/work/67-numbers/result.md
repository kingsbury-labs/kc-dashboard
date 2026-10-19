# 67. Numbers for every property: result

Files: `bin/collect_ga.py`, `src/Numbers/{GaCollector,StatusCollector,BugsinkCollector}.php`, `bin/collect-kc.php`,
`src/migrations/007_numbers.sql`, `public/api/numbers.php`, `public/assets/numbers.js`, `src/View.php`,
`public/index.php` (wiring), `config/properties.php` (new, placeholders for card 08), the fixture and three test files.

Tests: CollectGa 3, Numbers 7, NumbersApi 5. Full suite 329/329.

Mutations, each restored, then green:
- run() rethrows: failed "one failing source...", "StatusCollector...", "Bugsink...".
- Bugsink error not redacted: failed "Bugsink... token only in the header".
- Bugsink follows `next` to any host: failed the same test (15, not 3).
- numbers.php with checkFetchSite instead of checkApiRead: failed "foreign Host, no token..." (no token got 200).
None refused.

Calls:
- NumberStore and NumbersApi live in GaCollector.php: no file for them in the list.
- HttpClient has no headers, so BugsinkCollector has its own https-only curl GET with no redirects, so the token can't follow one.
- A failed run keeps that source's last good numbers and shows the error.

## Round two

1. `#[\SensitiveParameter]` on both Bugsink `$token` parameters; `collect-kc.php` sets `zend.exception_ignore_args` to 1.
2. `collect_ga.py` writes only `collect_ga.py failed: <Type>` to stderr.
3. Non-finite numbers become null; `allow_nan=False` on the dump.
4. A count that hits the page cap is stored with label `partial` and shown as "N+" (View and numbers.js).

New tests: trace without the token (args on), page cap partial, failure prints only the type, NaN and Infinity.
Mutations, each failed its test, restored: attribute removed (trace showed `'fake-bugsink-to...'`), isfinite removed
(ValueError), message added to stderr, partial forced false. Full suite 333/333.

Not done or unverified: the Bugsink API path (`/api/canonical/0/issues/`) and the status.sembr.co JSON shape are guesses, read loosely; the live GA path and both curl GETs are untested (no network). Realtime active users are stored and served, but the wall page doesn't show them yet (wall files not in scope). Nothing scheduled.
