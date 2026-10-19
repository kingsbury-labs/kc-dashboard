# 62. Page UI shell: search, Ask Claude, answers, clients

## Objective
The new-tab page at `http://127.0.0.1:8787/` is a working mobile-first shell:
a search box, the Ask Claude form (project picker from ProjectList, posts to
`public/api/ask.php` with the token, polls `answers.php`), the answers list,
and the clients panel from Rob's `_DASHBOARD.md` and `_PIPELINE.md`. An
elevated Apache shows a warning banner. A true fatal returns the same JSON
500 as any other error. `php ops/http-check.php` passes, which today fails
only "body does not contain the token".

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 62 (the whole card is the spec for this task)
- `D:\Dev\htdocs\_kc-dashboard\docs\SPEC.md`, Security and Features
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\60-ask\result.md` (what card 60 built: ProjectList, ask.php, answers.php)
- `D:\Dev\htdocs\_kc-dashboard\public\index.php`, `src\View.php`, `src\bootstrap.php`, `src\Http\Response.php`, `src\Http\RequestGuard.php`, `src\Http\Token.php`, `src\Process\ProcessRunner.php`, `src\Projects\ProjectList.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\PageTest.php`, `tests\Fakes\`
- `D:\Dev\htdocs\_kc-dashboard\ops\http-check.php` (what the live check expects)
- `c:\personas\cas\memory\design-slop.md` (patterns Rob has banned; none may ship)

## Context
- The real client files are `D:\Dev\htdocs\kingsburycreative\clients\_DASHBOARD.md`
  and `_PIPELINE.md`. Read them once to see the real table shapes, then build
  the fixtures under `tests/fixtures/clients/` with invented client names and
  details (no real names, emails or phone numbers in fixtures). Tests read
  only the fixtures.
- Mobile first, progressive enhancement: base CSS and the forms work on a
  phone with no JS (Ask Claude posts as a plain form when JS is off is fine
  if the guard allows it; otherwise the form shows a no-JS note). Desktop
  layout only through `min-width` queries. No `max-width` media query.
- Design: plain and functional; this shell gets the HUD theme in card 76, so
  do not build a theme now. Colours as CSS custom properties on `:root`, a
  dark variant under `prefers-color-scheme`. Nothing from the design-slop
  list: no eyebrow labels, no filled-plus-outlined button pair, no beige,
  no comma in a heading.
- `http-check.php` is run against the live Apache vhost (already installed,
  serving `public/` from this folder). It reads `data/token` itself; never
  print or copy the token anywhere.
- PHP 8.2 only. Edit PHP with Edit/Write only, never sed or node -e.

## Files you may touch
- `D:\Dev\htdocs\_kc-dashboard\public\index.php`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\app.js`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\app.css`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\clients.js`
- `D:\Dev\htdocs\_kc-dashboard\public\api\clients.php`
- `D:\Dev\htdocs\_kc-dashboard\src\View.php`
- `D:\Dev\htdocs\_kc-dashboard\src\bootstrap.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Http\Response.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Sys\Elevation.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Clients\DashboardParser.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Clients\PipelineParser.php`
- `D:\Dev\htdocs\_kc-dashboard\config\clients.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\Support\DomSinks.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\fixtures\clients\_DASHBOARD.md`
- `D:\Dev\htdocs\_kc-dashboard\tests\fixtures\clients\_PIPELINE.md`
- `D:\Dev\htdocs\_kc-dashboard\tests\fixtures\whoami\` (new files inside)
- `D:\Dev\htdocs\_kc-dashboard\tests\PageTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\PageUiTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\ClientsParserTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\ClientsApiTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\ElevationTest.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Http\RequestGuard.php` (round two, Fetch Metadata rule)
- `D:\Dev\htdocs\_kc-dashboard\tests\RequestGuardTest.php` (round two)
- `D:\Dev\htdocs\_kc-dashboard\ops\http-check.php` (added by the coordinator: fix the 0x08 byte on line 73 to `\b`, and add the same `PHP_SAPI !== 'cli'` exit guard as `bin/worker.php`)

## Done-test
From plan.md card 62, word for word: the "Done-test:" paragraph at the end
of card 62 (filters Page, PageUi, ClientsParser, ClientsApi, Elevation, and
`php ops/http-check.php` exits 0, then PageUiTest through ElevationTest).

Then the full suite passes through the runner
(`node D:/Dev/htdocs/_kc-dashboard/.claude/bin/project-test.cjs`, forward
slashes in bash). Mutation check through the runner, one at a time, naming
the failing tests and restoring: (a) render an answer with `innerHTML` in
`app.js`; (b) keep a `javascript:` link in the clients parser; (c) remove
the shutdown function from bootstrap. Then run green.

## Boundaries
If the permission check refuses a mutation run (it has refused ones that remove a security guard as "Security Weaken"), restore the code at once, do not retry or rephrase it, and list it in result.md as owed. No commit, no push, no install, nothing outward. No restart or config change
to Apache. Never print, log or copy the token. `project.md`'s Never list
applies. A file not in the list above is out of scope; if one must change,
stop and say so in result.md.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, and any call made
and why. Say what was not done.
