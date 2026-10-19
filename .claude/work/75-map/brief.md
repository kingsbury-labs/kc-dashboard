# 75. Project map and running strip

## Objective
Build plan.md card 75 as written: the card's text is the spec for this
task, and its "Done-test:" paragraph is what done means.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 75 (the whole card) and the cards it names as dependencies
- `D:\Dev\htdocs\_kc-dashboard\docs\SPEC.md`, Security and the feature this card builds
- The `result.md` of each earlier card this one builds on, under `D:\Dev\htdocs\_kc-dashboard\.claude\work\`
- The existing code the card changes, plus `tests\Fakes\` and `tests\Support\` for fakes and helpers to reuse
- `c:\personas\cas\memory\design-slop.md` if the card has any UI (patterns Rob has banned; none may ship)

## Context
- Tests use fakes only: no real network, model, `claude.exe` or process
  calls beyond what the card's done-test itself names (a child `php.exe`
  is fine).
- System tools are absolute paths in `config/executables.php`
  (`C:\Windows\System32\<name>.exe`), started only through ProcessRunner.
- UI is mobile first with progressive enhancement: works on a phone with no
  JS; desktop layout through `min-width` only; no `max-width` media
  query; JS writes text with `textContent` only (DomSinks).
- PHP 8.2 only. Edit PHP with the Edit/Write tools only, never sed or node -e.
- From the card 73 audit: escape every repo-controlled column, not only the subject: branch, last_subject, loop_card, loop_note and error. ProjectsUiTest plants the onerror payload in each. Add JSON_INVALID_UTF8_SUBSTITUTE to Response::json so one invalid UTF-8 commit subject cannot blank api/projects.php, with a test.
- Every `public/api/*.php` GET goes through `RequestGuard::checkApiRead()` (same-origin, not a navigation, valid `X-KC-Token`; card 74/74a) and every POST through `checkWrite()`; ApiGuardSweepTest fails any endpoint that does not. JS sends `X-KC-Token` on every fetch.

## Files you may touch
- `D:\Dev\htdocs\_kc-dashboard\public\api\projects.php`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\projects.js`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\now.js`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\app.css`
- `D:\Dev\htdocs\_kc-dashboard\src\View.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\ProjectsApiTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\ProjectsUiTest.php`

- `D:\Dev\htdocs\_kc-dashboard\src\Http\Response.php` (UTF-8 substitute only)
- `D:\Dev\htdocs\_kc-dashboard\tests\PageTest.php` (its Response test only)
- `D:\Dev\htdocs\_kc-dashboard\tests\PageUiTest.php` (the JS file inventory only)
- `D:\Dev\htdocs\_kc-dashboard\public\index.php` (passing the map and now sources only)

## Done-test
From plan.md card 75, word for word: the "Done-test:" paragraph of card
75. Every filter it names passes.

Then the full suite passes through the runner
(`node D:/Dev/htdocs/_kc-dashboard/.claude/bin/project-test.cjs`, forward
slashes in bash; `--filter <name>` for one). Mutation check through the
runner: break each guard the done-test exists for (at least two), one at a
time with the Edit tool, run, name the tests that failed, restore. Then run
green.

## Boundaries
If the permission check refuses a mutation run (it has refused ones that remove a security guard as "Security Weaken"), restore the code at once, do not retry or rephrase it, and list it in result.md as owed. No commit, no push, no install, nothing outward. No change to Apache, Task
Scheduler or any other project's files (reading is fine where the card says
so). Never read a secret value. `project.md`'s Never list applies. A file
not in the list above is out of scope; if one must change, stop and say so
in result.md.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, and any call made
and why. Say what was not done.
