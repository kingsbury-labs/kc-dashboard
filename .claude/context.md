---
session: 4
last_updated: 2026-10-12
continue_with: Cards 07-09 by hand (vhost install, credentials, fallback model decision), then remove the STOP file and restart claude-loop at card 16 Project picker.
blockers: Cards 07 vhost install, 08 credentials, 09 fallback model decision wait on Rob. Until 07, port 80 serves the whole project folder, so any site can make Apache run tests/run.php.
---

# KC Dashboard context

## To Resume
Session 5. main on origin (kingsbury-labs/kc-dashboard) ends with the session 4 handoff commit; cards 01, 04-06, 10-15 and 13a are in it.
THIS WINDOW:
1. Cards 07-09 (Rob's hands).
2. Remove the loop's STOP file and restart claude-loop; next card is 16 Project picker.
3. Issue 3 holds non-blocking follow-ups from cards 14-15.

## What exists
- On main: 01 test runner, 04 spec, 05 logger, 06 vhost snippet, 10 request guard and token, 11 responses and front controller, 12 job queue, 13 worker and registry, 13a runner fails on a broken test file, 14 safe directory check, 15 ProcessRunner.
- Stale branch `wip/14-safedir` still on origin; card 14 landed from it, safe to delete.
- Plan: `.claude/work/plan.md` (cards 01-26), state: `.claude/work/checklist.json`, notes: `.claude/work/log.md`.
- Remotes: `origin` is the public kingsbury-labs repo (issues live here); `private-archive` is the old rob-kingsbury repo.

## Decided
- Loop runs use `--merge-allowed`; cards land straight on main (no CI, no PRs).
- Card 11 follow-ups (shutdown handler, emit after headers sent, untested bootstrap handler) are non-blocking, listed in log.md.

## Recent sessions
- **S4 (2026-10-12, loop run):** cards 13a, 14 and 15 passed the gate and pushed to main; loop stopped on the STOP file. Issues 1-2 closed as fixed, issue 3 filed for card 14-15 follow-ups.
- **S3 (2026-10-12, loop run):** cards 05, 10-13 pushed to main. Card 14 parked on broken backslashes; runner passing on a parse error stopped the loop.
- **S2 (2026-10-12, loop run):** card 05 blocked on the permission check refusing worker test runs. Nothing shipped.
