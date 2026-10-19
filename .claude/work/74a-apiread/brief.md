# 74a. Token on every API read

## Objective
Every `public/api/*.php` endpoint goes through either `RequestGuard::checkWrite()` (POST) or `RequestGuard::checkApiRead()` (GET: same-origin, not a navigation, valid `X-KC-Token`), and a test sweeps the folder so a new endpoint without one fails the suite. The page's JS sends the token on every API GET. `ops/http-check.php` checks that an API GET without the token is refused.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\74-running\result.md` (card 74 added `checkApiRead`, used by now.php)
- `D:\Dev\htdocs\_kc-dashboard\src\Http\RequestGuard.php`, `tests\RequestGuardTest.php`
- `D:\Dev\htdocs\_kc-dashboard\public\api\` (every file), `src\Jobs\AskHandler.php` (AskApi), `src\View.php`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\app.js`, `clients.js`
- `D:\Dev\htdocs\_kc-dashboard\tests\AskApiTest.php`, `tests\ClientsApiTest.php`, `tests\NowApiTest.php`
- `D:\Dev\htdocs\_kc-dashboard\ops\http-check.php`

## Context
- Why: card 74's audit showed a navigation (iframe, GET form) from any site passes checkRead, and GET endpoints either start processes or return private data (Ask answers, client lists). A token on every API read closes this for every endpoint at once.
- `index.php` stays on checkRead: it must be reachable by navigation (the new tab), and it is where the token is handed out in its meta tag.
- Each endpoint's existing behaviour stays the same apart from the guard.

## Files you may touch
- `D:\Dev\htdocs\_kc-dashboard\src\Http\RequestGuard.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Jobs\AskHandler.php` (the AskApi answers path only)
- `D:\Dev\htdocs\_kc-dashboard\src\View.php` (the clients endpoint path only)
- `D:\Dev\htdocs\_kc-dashboard\public\api\answers.php`
- `D:\Dev\htdocs\_kc-dashboard\public\api\clients.php`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\app.js`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\clients.js`
- `D:\Dev\htdocs\_kc-dashboard\ops\http-check.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\ApiGuardSweepTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\AskApiTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\ClientsApiTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\RequestGuardTest.php`

## Done-test
`php tests/run.php` with `--filter ApiGuardSweep`, `AskApi`, `ClientsApi`, `NowApi` and `RequestGuard` each pass. ApiGuardSweepTest: for every `public/api/*.php`, a GET with no token and a navigate-mode GET get 403 with no side effects, and the sweep fails if it finds zero files. AskApiTest and ClientsApiTest: answers and clients without the token give 403; with it, same output as before. `app.js` and `clients.js` send `X-KC-Token` on every fetch (grep). `php ops/http-check.php` exits 0 and includes a check that `/api/answers.php` without the token gets 403.

Then the full suite passes through the runner (`node D:/Dev/htdocs/_kc-dashboard/.claude/bin/project-test.cjs`, forward slashes in bash). Mutation check through the runner: drop the token check from checkApiRead, run, name the failing tests, restore, run green.

## Boundaries
If the permission check refuses a mutation run (it has refused ones that remove a security guard as "Security Weaken"), restore the code at once, do not retry or rephrase it, and list it in result.md as owed. No commit, no push, no install, nothing outward. No change to Apache. Never print the token. `project.md`'s Never list applies. A file not in the list above is out of scope; if one must change, stop and say so in result.md.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests added, the mutation result naming the tests that failed, and any call made and why. Say what was not done.
