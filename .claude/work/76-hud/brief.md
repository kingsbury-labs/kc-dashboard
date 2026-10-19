# 76. HUD theme and orb

## Objective
Build plan.md card 76 as written: the card's text is the spec for this
task, and its "Done-test:" paragraph is what done means.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 76 (the whole card) and the cards it names as dependencies
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
- The HUD theme replaces the plain shell look from card 62; keep every no-JS path working.
- From the card 75 audit, small and in this card: View.php:32-33 renders strip() and tiles() outside the per-source try, and bootstrap turns warnings into exceptions, so one non-scalar value in data/now-cache.json 500s the whole page. Read the snapshot with a byte cap and keep only string/int fields, and render each section in its own try (test: a snapshot with an array as a session project still renders the page). Add JSON_INVALID_UTF8_SUBSTITUTE to NowService.php:126.

## Files you may touch
- `D:\Dev\htdocs\_kc-dashboard\public\assets\hud.css`
- `D:\Dev\htdocs\_kc-dashboard\public\assets\orb.js`
- `D:\Dev\htdocs\_kc-dashboard\src\View.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\HudTest.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Running\NowService.php` (the json_encode flag only)
- `D:\Dev\htdocs\_kc-dashboard\tests\ProjectsUiTest.php` (the bad-snapshot test only)

## Done-test
From plan.md card 76, word for word: the "Done-test:" paragraph of card
76. Every filter it names passes.

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
