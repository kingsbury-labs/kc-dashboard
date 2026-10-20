# 68. Chrome extension and Task Scheduler script

## Objective
Build plan.md card 68 as written: the card's text is the spec for this
task, and its "Done-test:" paragraph is what done means.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 68 (the whole card) and the cards it names as dependencies
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
- Never run `bin/register-tasks.ps1` without `-WhatIf`, and make the script's `-WhatIf` path skip `Register-ScheduledTask` entirely (print what it would do), so a test can never register a real task. Rob registers the tasks himself later (card 52). Powershell is `C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe`, added to the test's own allowlist only, not to `config/executables.php`.
- Wiring a new panel or script into the page usually needs `public/index.php` and `src/View.php`; both are in scope for that wiring only, even if not listed below. Any test may gain a case for this card. Edit PHP with Edit/Write only (no heredocs, `php -r`, sed or `node -e`).
- UI lives inside card 76b`s KC Command Center skin: read `D:/Dev/htdocs/_kc-dashboard/.claude/work/mission-control.md` and look at `design-refs/1-jarvis-command-center.png`. New controls and panels use its panel class, colors and motion rules; nothing light-themed, nothing that looks like a plain list.

## Files you may touch
- `D:\Dev\htdocs\_kc-dashboard\chrome-extension\manifest.json`
- `D:\Dev\htdocs\_kc-dashboard\chrome-extension\newtab.html`
- `D:\Dev\htdocs\_kc-dashboard\chrome-extension\newtab.js`
- `D:\Dev\htdocs\_kc-dashboard\bin\register-tasks.ps1`
- `D:\Dev\htdocs\_kc-dashboard\tests\ExtensionTest.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\TasksScriptTest.php`
- `D:\Dev\htdocs\_kc-dashboard\public\index.php` (wiring only)
- `D:\Dev\htdocs\_kc-dashboard\src\View.php` (wiring only)

## Done-test
From plan.md card 68, word for word: the "Done-test:" paragraph of card
68. Every filter it names passes.

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
