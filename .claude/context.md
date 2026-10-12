---
session: 3
last_updated: 2026-10-12
continue_with: Approve and fix tests/run.php exiting 0 on a parse error (issue 1), then fix card 14's backslashes on wip/14-safedir (issue 2), then cards 07-09 by hand, then restart the loop.
blockers: Loop is stopped until run.php fails on a parse error (issue 1). Cards 07 vhost install, 08 credentials, 09 fallback model decision wait on Rob. Until 07, port 80 serves the whole project folder, so any site can make Apache run tests/run.php.
---

# KC Dashboard context

## To Resume
Session 4. main on origin (kingsbury-labs/kc-dashboard) ends with the session 3 handoff commit; cards 01, 04, 05, 06, 10-13 are in it.
THIS WINDOW:
1. Issue 1: make tests/run.php exit non-zero when a test file fails to parse (card 11's exception handler swallows the fatal). Needs Rob's OK as its own card.
2. Issue 2: card 14 on wip/14-safedir, restore the lost backslash literals, run the mutation checks in its result.md.
3. Cards 07-09 (Rob's hands), then restart claude-loop.

## What exists
- On main: 01 test runner, 04 spec, 05 logger, 06 vhost snippet, 10 request guard and token, 11 responses and front controller, 12 job queue, 13 worker and registry.
- Card 14 safe directory check parked on wip/14-safedir (pushed). Details in `.claude/work/log.md`.
- Plan: `.claude/work/plan.md` (cards 01-26), state: `.claude/work/checklist.json`.
- Remotes: `origin` is the public kingsbury-labs repo (issues live here now); `private-archive` is the old rob-kingsbury repo.

## Decided
- Loop runs use `--merge-allowed`; cards land straight on main (no CI, no PRs).
- Card 11 follow-ups (shutdown handler, emit after headers sent, untested bootstrap handler) are non-blocking, listed in log.md.

## Recent sessions
- **S3 (2026-10-12, loop run):** headless test runs fixed; cards 05, 10, 11, 12, 13 passed the gate and pushed to main. Card 14 parked after the auditor found two junction bypasses and the fix broke its backslashes; the runner reporting a parse error as a pass stopped the loop. Archive issue 3 closed, issues 1-2 filed.
- **S2 (2026-10-12, loop run):** card 05 blocked on the permission check refusing worker test runs. Nothing shipped.
- **S1 (2026-10-12, loop run):** cards 01, 04, 06 merged. Card 05 parked on its mutation check.
