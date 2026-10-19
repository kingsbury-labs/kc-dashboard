# 73b result

Files changed: `src/Projects/GitStatus.php` (INERT: `branch.<name>.<key>` now any key, new `core.hookspath`, one-line comment on each), `tests/ProjectScannerTest.php`.

Test added: "editor branch keys and core.hookspath are accepted; extensions.worktreeconfig and filter.x.clean are refused". Alpha config has `branch.main.github-pr-base-branch`, `branch.x.gk-last-accessed`, `branch.main.github-pr-owner-number`, `core.hookspath` and scans clean; beta (worktreeconfig) and gamma (filter.x.clean) are refused. The real-git canary test is untouched and passes.

Mutation: narrowed the branch pattern back to remote|merge|vscode-merge-base. The new test failed ("expected NULL, got 'repo config not trusted: branch.main.github-pr-base-branch'"). Restored, 14 passed. Full suite 202 passed, 0 failed.

Call: the branch key is `[a-z0-9-]+` after the last dot, since git lowercases variable names; branch names containing dots still match.

Not done: collect-projects.php not run, as briefed.
