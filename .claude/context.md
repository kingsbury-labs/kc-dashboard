---
session: 11
last_updated: 2026-10-19
continue_with: Plan 5.1 is built (21 cards). Rob first looks at the live command center against sample 1 and says what to tweak. Then the follow-up cards 81-86 that card 69 filed (83 needs his call first), through the loop or in-window as he says.
blockers: Rob's calls listed under Waiting on Rob. coordinator-only.cjs refuses git commit and push from the main window, so commits go through a short background agent. The auto-mode check refuses mutation runs that remove a security guard or reach the scheduler; they are logged as owed, never routed around.
---

# KC Dashboard context

## To Resume
Session 11. main on origin (kingsbury-labs/kc-dashboard, public) at `e4538e0 Card 69: cross-card security audit`, plus this handoff. Suite: 395 passing; `ops/http-check.php` exits 0. Progress page: https://claude.ai/artifact/VEKdEfoGMwpkiT46Q9SvDP
THIS WINDOW:
1. Ask Rob how the live page at http://127.0.0.1:8787/ reads against `design-refs/1-jarvis-command-center.png`. Nobody has compared them by eye yet (Rob cut the screenshot pass for speed). Tweaks go to a new card against `mission-control.md`. Apache may need starting: `C:/xampp/apache/bin/httpd.exe -d C:/xampp/apache` in the background.
2. Cards 81-86 (plan.md, "Filed by card 69"; detail in `.claude/work/69-audit/report.md`). Not briefed yet. 83 waits on Rob's finance call. Ask Rob whether these go through the loop (his waiver covered plan 5.1 only).
3. The per-card Opus audits for 76b, 79, 80, 68 were folded into card 69's audit (no live attack found).

## What exists
- Plan 5.1 built and on main: 01, 04-07, 10-15, 13a, 59, 60, 62, 63, 67, 70-78, 73b, 74a, 76b, 79, 80, 68, 69. Live at / (KC Command Center), /wall.php, /projects.php, /briefings.php, served by Apache from `public/` in the working tree.
- Every `public/api/*.php` GET is token-gated (`checkApiRead`), writes are POST via `checkWrite` (ask, feedback, brief, remember, wall-stream ticket); `ApiGuardSweepTest` walks `public/**`; http-check lists WRITES and PAGES by name, so a new endpoint must be added there.
- `bin/register-tasks.ps1` (7 tasks under `\KC Dashboard\`, S4U) is written, never run. `chrome-extension/` (MV3, no permissions) not loaded.
- Plan: `.claude/work/plan.md`. Design: `mission-control.md`. Decisions, owed mutations, audit notes: `log.md`. State: `checklist.json`.
- `.claude/settings.json` holds browser-site approvals, uncommitted (`scope_ignore`).

## Waiting on Rob
- Look at the command center against sample 1.
- Card 83: finance evidence (free text) now lands in vault Briefings and the briefings table; the finance contract never covered that. OK it, or scrub first.
- OK one real `claude -p` canary in untrusted mode; confirm the daily read may send finance evidence to Anthropic.
- Allow `project-test.cjs` past the auto-mode check for the owed mutations (cards 60, 62, 74, 79 memory neutralise, 68 -WhatIf).
- php.ini `date.timezone` is Europe/Berlin (no-JS clock wrong). Apache: `/wall` route, access log without query strings.
- Card 08 config values, then 28 (load extension) and 52 (run register-tasks.ps1). Issue 3 stays open for Later.

## Decided
- Revision 5.1 (Rob, 2026-10-19): the UI is a JARVIS command center like his sample 1, always dark and animated, every agent wired to a central AI core. Short all-caps panel titles are part of that look.
- Session 10 (Rob): speed over gating for 76b-69; per-card audits and the screenshot pass deferred, the audits absorbed by card 69.
- No Hermes. Attention engine design (2026-10-17) stands: mail out, no-API rule, daily read via `claude -p`. kc-finance read-only through `alerts.json` schema 1; finance links only under `http://127.0.0.1:8000/`.

## Recent sessions
- **S10 (2026-10-19, build):** finished plan 5.1 in-window: 76b command center, 79 briefing and memory (sent back once: briefing built on a GET, now POST-only), 80 voice, 68 extension and task script, 69 cross-card audit (no live attack, gaps filed as 81-86). Rob cut per-card audits for speed. Apache was down; started it. Fixed http-check's card-70 gap (feedback.php missing from WRITES). A card 68 mutation reached the real Register-ScheduledTask; checked read-only, nothing registered, test wrapper fixed.
- **S9 (2026-10-19, build):** built 15 cards plus 73b and 74a in-window with audits; Rob rejected the look and gave samples; card 76b and `mission-control.md` record the JARVIS design.
- **S8 (2026-10-19, research):** JARVIS research; plan revision 5 with cards 73-80; Rob waived the loop for this build.
