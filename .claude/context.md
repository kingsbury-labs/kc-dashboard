---
session: 10
last_updated: 2026-10-19
continue_with: Finish plan revision 5 in-window (loop still waived by Rob for this build), one agent per card, coordinate gate per card, a background agent commits each card on main with no trailer. Remaining build order 79, 80, 68, then 69 (cross-card security audit). Briefs for all four are written in .claude/work/.
blockers: none for the build. coordinator-only.cjs refuses git commit and push from the main window, so each card's commit goes through a short background agent (exempt). The auto-mode check refuses mutation runs that remove a security guard ("Security Weaken" / "Create RCE Surface"); they are logged as owed, never routed around.
---

# KC Dashboard context

## To Resume
Session 10. main on origin (kingsbury-labs/kc-dashboard, public) ends with the session 9 handoff, after `4103362 Card 78: vault`. Suite: 348 passing.
THIS WINDOW:
1. Card 79 (briefing, memory, history): `.claude/work/79-briefing/brief.md`. Add it to `checklist.json` with `start_sha` = HEAD (scratch helper is gone; a 5-line node edit does it), start one Opus implementer with the brief path, gate with `node D:/Dev/htdocs/_kc-dashboard/.claude/bin/project-test.cjs --check-scope <brief> <sha>`, read the diff, Opus security-auditor, full suite, `--verify`, `--pass 79`, then a background agent: `git add -A src tests bin config public ops .claude/work`, commit `Card 79: ...` with no trailer, check `git status --porcelain` is empty, push.
2. Card 80 (voice out, not risky, Sonnet, no audit), card 68 (extension and task script, Opus + audit), card 69 (security-auditor role: cross-card audit, its done-test is in plan.md).
3. Audits so far sent most cards back once; send findings to the same worker with SendMessage and widen the brief's file list when a fix needs another file (`--check-scope` reads only the bullets).
4. Stop on a card failing twice, the usage cap, or anything needing Rob. Log in `.claude/work/log.md` and go on.

## What exists
- Built and on main: 01, 04-07, 10-15, 13a, and this build's 59, 60, 62, 63, 67, 70, 71, 72, 73, 73b, 74, 74a, 75, 76, 77, 78. Live at http://127.0.0.1:8787/ and /wall.php (Apache serves `public/` from the working tree).
- Every `public/api/*.php` GET is token-gated (`RequestGuard::checkApiRead`), POST uses `checkWrite`; `ApiGuardSweepTest` walks `public/**` and fails any endpoint that is not. Pages (index, wall) are the only exceptions.
- Untrusted `claude -p` runs with `--tools "" --restricted --strict-mcp-config --no-session-persistence` plus a deny list (`ClaudeRunner::UNTRUSTED`). `Vault::neutralise()` is the shared markdown neutraliser; card 79 must use it for every untrusted field.
- Plan: `.claude/work/plan.md` revision 5. Decisions, owed mutations and audit notes per card: `.claude/work/log.md` (session 9 section). State: `checklist.json`.

## Waiting on Rob
- Allow `project-test.cjs` past the auto-mode check, then run the owed guard mutations for cards 60, 62, 74 (list in log.md).
- OK one real `claude -p` canary in untrusted mode before the daily read runs for real; confirm the daily read may send finance evidence to Anthropic (finance-contract.md covers only DB and page).
- Apache: a `/wall` route, and an access log format without query strings (wall-stream tickets land in `access.log`).
- Card 08: real values in `config/properties.php` and `config/collectors.php`; cards 28 and 52 after 68.
- Issue 3 (ProcessRunner follow-ups) stays open for Later.

## Decided
- Revision 5 (Rob, 2026-10-19): JARVIS layer on revision 4. Rob waived the loop for this build; commits go straight to main, not PRs.
- No Hermes. Attention engine design (2026-10-17) stands: mail out, no-API rule, daily read via `claude -p`. kc-finance read-only through `alerts.json` schema 1; finance links only under `http://127.0.0.1:8000/`.

## Recent sessions
- **S9 (2026-10-19, build):** rehearsed every gate with Rob present (no prompts), then built 15 cards plus 73b and 74a in-window, each through scope check, an Opus security audit on risky paths and the full suite; audits drove a second round on most cards (untrusted mode as an allow list, token on every API read, git config filter driver, XXE hardening, feed ranking abuse, neutraliser gaps). Mutation runs that remove a guard were refused by the auto-mode check on 60, 62, 74 and are owed.
- **S8 (2026-10-19, research):** JARVIS research; plan revision 5 with cards 73-80; Rob waived the loop for this build.
- **S7 (2026-10-17/19, interactive):** kc-finance contract; plan revision 4 (cards 59-72); loop copied into the project.
