# 73b. Scanner: accept harmless repo config keys

## Objective
The project scanner stops refusing repos over config keys git never acts on during `config`, `status` or `log`. A real scan of `D:\Dev\htdocs` on 2026-10-19 refused 21 of 52 repos: 13 over editor metadata under `branch.<name>.*` (`github-pr-base-branch`, `github-pr-owner-number`, `gk-last-accessed`), 7 over `core.hookspath`, 1 over `extensions.worktreeconfig`.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\73-scanner\result.md` (both rounds)
- `D:\Dev\htdocs\_kc-dashboard\src\Projects\GitStatus.php` (the `INERT` list, around line 25)
- `D:\Dev\htdocs\_kc-dashboard\tests\ProjectScannerTest.php`

## Context
- Accept any `branch.<name>.<key>`. No branch key makes `status`, `log` or `config` run a command; branch keys only steer merge, rebase, pull and push, which the scanner never runs.
- Accept `core.hookspath`. `status`, `log` and `config` fire no hooks, and the index is never written (GIT_OPTIONAL_LOCKS=0).
- Keep refusing `extensions.worktreeconfig`: it makes git also read `.git/config.worktree`, which `config --local --list` does not list, so a filter there would get past the check. Keep refusing filter, include, includeIf, core.fsmonitor, core.pager, diff.*, and every other unknown key.
- Comment beside each new pattern saying why it is inert, in one line.

## Files you may touch
- `D:\Dev\htdocs\_kc-dashboard\src\Projects\GitStatus.php`
- `D:\Dev\htdocs\_kc-dashboard\tests\ProjectScannerTest.php`

## Done-test
`php tests/run.php --filter ProjectScanner` passes. New cases: a repo whose local config has `branch.main.github-pr-base-branch`, `branch.x.gk-last-accessed` and `core.hookspath` scans normally; one with `extensions.worktreeconfig` is still refused; `filter.x.clean` is still refused (the real-git canary test stays). Full suite green through the runner (`node D:/Dev/htdocs/_kc-dashboard/.claude/bin/project-test.cjs`). Mutation: drop the `branch.*` pattern, run, name the failing test, restore, run green.

## Boundaries
If the permission check refuses a mutation run, restore at once, do not retry, and list it in result.md as owed. No commit, no push, no install, nothing outward. Do not run collect-projects.php. Edit PHP with Edit/Write only, never sed, `node -e` or `php -r`. `project.md`'s Never list applies.

## Report
Write `result.md` beside this file, under 150 words: files changed, tests added, the mutation result naming the failing test. Say what was not done.
