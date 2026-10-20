---
session: 10
last_updated: 2026-10-19
continue_with: Card 76b first (KC Command Center, the JARVIS look Rob asked for), then 79, 80, 68 and 69, in-window (loop still waived for this build), one agent per card, coordinate gate per card, a background agent commits each card on main with no trailer. All five briefs are written in .claude/work/.
blockers: none for the build. coordinator-only.cjs refuses git commit and push from the main window, so each card's commit goes through a short background agent (exempt). The auto-mode check refuses mutation runs that remove a security guard ("Security Weaken" / "Create RCE Surface"); they are logged as owed, never routed around.
---

# KC Dashboard context

## To Resume
Session 10. main on origin (kingsbury-labs/kc-dashboard, public) ends with the session 9 design handoff, after `4103362 Card 78: vault`. Suite: 348 passing.
THIS WINDOW:
1. Card 76b, KC Command Center: Rob rejected the card 75-77 look ("NOTHING like the black JARVIS style dashboard with the animations"). Read `.claude/work/mission-control.md`, then open every image in `design-refs/` (gitignored; `1-jarvis-command-center.png` is the target). Brief: `.claude/work/76b-command-center/brief.md`. Add 76b to `checklist.json` with `start_sha` = HEAD, start one Opus implementer with the brief path, gate (`node D:/Dev/htdocs/_kc-dashboard/.claude/bin/project-test.cjs --check-scope <brief> <sha>`, read the diff, Opus security-auditor, full suite, `--verify`, `--pass 76b`). Before committing, screenshot the live page at 1600 and 400 wide and compare it with sample 1 yourself; if it doesn't read as the sample, send it back. Commit through a background agent: `git add -A src tests bin config public ops .claude/work`, message `Card 76b: KC Command Center`, no trailer, `git status --porcelain` empty, push.
2. Then 79 (Opus + audit), 80 (Sonnet, no audit), 68 (Opus + audit), 69 (security-auditor: cross-card audit, done-test in plan.md). Each brief already points at the new skin.
3. Audit findings go back to the same worker (SendMessage). When a fix needs another file, add it to the brief's file bullets (`--check-scope` reads only the bullets).
4. Stop on a card failing twice, the usage cap, or anything needing Rob. Log it in `.claude/work/log.md` and go on.

## What exists
- Built and on main: 01, 04-07, 10-15, 13a, and this build's 59, 60, 62, 63, 67, 70, 71, 72, 73, 73b, 74, 74a, 75, 76, 77, 78. Live at http://127.0.0.1:8787/ and /wall.php (Apache serves `public/` from the working tree). The data works; the look is what 76b replaces.
- Every `public/api/*.php` GET is token-gated (`RequestGuard::checkApiRead`), POST uses `checkWrite`; `ApiGuardSweepTest` walks `public/**` and fails any endpoint that is not. Pages (index, wall) are the only exceptions.
- Untrusted `claude -p` runs with `--tools "" --restricted --strict-mcp-config --no-session-persistence` plus a deny list (`ClaudeRunner::UNTRUSTED`). `Vault::neutralise()` is the shared markdown neutraliser; card 79 must use it for every untrusted field.
- Plan: `.claude/work/plan.md` revision 5.1 (76b added). Design: `mission-control.md`. Decisions, owed mutations and audit notes per card: `log.md`. State: `checklist.json`.
- `.claude/settings.json` holds three browser-site approvals from this session, uncommitted (it is a `scope_ignore` file).

## Waiting on Rob
- Allow `project-test.cjs` past the auto-mode check, then run the owed guard mutations for cards 60, 62, 74 (list in log.md).
- OK one real `claude -p` canary in untrusted mode before the daily read runs for real; confirm the daily read may send finance evidence to Anthropic (finance-contract.md covers only DB and page).
- Apache: a `/wall` route, and an access log format without query strings (wall-stream tickets land in `access.log`).
- Card 08: real values in `config/properties.php` and `config/collectors.php`; cards 28 and 52 after 68.
- Issue 3 (ProcessRunner follow-ups) stays open for Later.

## Decided
- Revision 5.1 (Rob, 2026-10-19): the UI is a JARVIS command center like his sample 1, always dark and animated, every agent connected to a central AI core. Panel titles in short all-caps are part of that look (they sit inside panels, not above a page headline).
- Revision 5: JARVIS layer on revision 4. Rob waived the loop for this build; commits go straight to main, not PRs.
- No Hermes. Attention engine design (2026-10-17) stands: mail out, no-API rule, daily read via `claude -p`. kc-finance read-only through `alerts.json` schema 1; finance links only under `http://127.0.0.1:8000/`.

## Recent sessions
- **S9 (2026-10-19, build):** rehearsed every gate with Rob present, then built 15 cards plus 73b and 74a in-window, each through scope check, an Opus security audit on risky paths and the full suite; audits drove a second round on most cards. Guard mutations refused on 60, 62, 74 are owed. Rob then rejected the look and gave samples; card 76b and `mission-control.md` record the JARVIS command center design.
- **S8 (2026-10-19, research):** JARVIS research; plan revision 5 with cards 73-80; Rob waived the loop for this build.
- **S7 (2026-10-17/19, interactive):** kc-finance contract; plan revision 4 (cards 59-72); loop copied into the project.
