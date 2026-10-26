---
session: 12
last_updated: 2026-10-25
continue_with: Rob reviews cockpit prototype v3 (http://127.0.0.1:8791/?boot=1) and says what to change; my open notes are galaxy density and the reply thread covering the galaxy at 1920. Once he likes it, rewrite docs/AGENTOS.md sections 4-6 from .claude/work/agentos/ux/ux-direction.md and re-plan cards 87-106, then Rob approves the spec (card 87) and phase 1 builds through the loop.
blockers: Rob's review of v3 and approval of docs/AGENTOS.md. The WSL/Docker CI-host gap (below) is parked for him.
---

# KC Dashboard context

## To Resume
Session 12. main on origin (kingsbury-labs/kc-dashboard, public) at the "Agent OS spec and the phase 1 plan" commit. The loop's files are untracked here on purpose (8b933c9; `.git/info/exclude`), as is `.claude/work/agentos/` (prototypes and working notes). Issue 3 open (Later).
THIS WINDOW:
1. Start the v3 server if it is down: `c:\xampp\php\php.exe -S 127.0.0.1:8791 -t D:\Dev\htdocs\_kc-dashboard\.claude\work\agentos\prototype-v3` in the background. Ask Rob for his read of v3. Changes go to one Opus design agent with taste-skill and ux-patterns loaded, working in prototype-v3 with its progress.md.
2. After Rob is happy: fold ux-direction.md into docs/AGENTOS.md (sections 4-6, the head is gone, the file galaxy and the conversation bar are in), re-plan plan 6 (cards 87-106) with the planner, then Rob approves card 87.
3. Offer the parked items under Waiting on Rob.

## What exists
- The dashboard (plan 5.1) is unchanged since S10: live at http://127.0.0.1:8787/ from `public/`, suite 399 at last count.
- Agent OS ("KC Cockpit") work, all under `.claude/work/agentos/` unless named: `research.md`, `speedup.md`, `data-spike.md` (transcript index: 44 s full, 1 s incremental), `ux/ux-direction.md` plus wireframes, `prototype/` (v2, rejected: condensed, head was gross) and `prototype-v3/` (six tabs, conversation bar, file galaxy in three.js). Spec: `docs/AGENTOS.md` (sections 4-6 now superseded by ux-direction.md). Plan 6 in `plan.md`, cards 87-106. References: `design-refs/agentos/` (gitignored, copyrighted stills).
- Loop work this session, global plus every copy (verified all 12 copies match the globals): narrow gate and proof by size (card 01), commit and push in code with the project's hooks replayed (01, fixed for `$CLAUDE_PROJECT_DIR` in card 10, confirmed live on SparrowBook 101s1), reused-PID cleanup fix (05), progress files for every agent (global rule 10), detached loop start `C:\Users\roban\.claude\bin\loop-start.cjs` (09). Briefs and results in `agentos/01-10`. Local loop commits in the private repos are unpushed; each loop's next push carries them.
- Global CLAUDE.md rules 9 (agent model and effort by size) and 10 (progress files) added this session. `coordinator-only.cjs` now lets the window commit and push bookkeeping when no loop runs.

## Waiting on Rob
- v3 review, then spec approval (card 87).
- CI host: the WSL distro idles out after 24 h and killed the runner mid-job; Docker Desktop was also down. SparrowBook added `C:\Users\roban\wsl-keepalive.cmd` (manual). Proposed: runner-watchdog checks both, plus a CI-host line in the cockpit's System drawer.
- loop-start.cjs is not yet proven against closing VS Code (VS Code runs in a job object).
- Rotate the Stripe key flagged by the secret-leak scanner on 2026-10-23, then `node C:\Users\roban\.claude\hooks\secret-leak-scan.cjs --ack`. Rob: another time.
- Carried from S10: card 83 finance evidence call; one `claude -p` canary; owed mutation runs; php.ini timezone; cards 08, 28, 52; cards 81-86 unbriefed.

## Decided
- Cockpit (Rob, 2026-10-23/24): desktop first, loopback only, workers are real `claude` sessions on his Max login, no API. Max 20x through October; parallel loops across projects allowed. Agents never send email. The character is Cas (CAS wordmark, "Coordinator of Agents and Sessions" once in the boot); the 3D head is dropped for the file galaxy. Animated boot replaces skeletons in the cockpit (exception to global rule 8). Face recognition is a later, for-fun phase (`docs/AGENTOS.md`, "Cas knows who is there").
- Loop files stay out of public repos (kc-dashboard, kc-cms-app untracked and pushed; SparrowBook already excluded).
- Earlier: JARVIS look (5.1), no Hermes, mail out of the dashboard, kc-finance read-only via `alerts.json`.

## Recent sessions
- **S11 (2026-10-23 to 25, Agent OS and loop speed):** Rob widened the project into an Agent OS cockpit. Research, a speed diagnosis (small cards 11-14 min) and a transcript-index spike; loop fixes 01, 04, 05, 06, 09, 10 carried into every copy, with SparrowBook's session coordinating its own copy; loop files taken out of the two public repos. Spec and plan 6 written; v2 prototype rejected; a UX-specialist pass produced tabs, a conversation bar and the file galaxy; v3 built and waiting on Rob. Two Claude Code crashes (likely memory pressure); work resumed from progress files.
- **S10 (2026-10-19, build):** finished plan 5.1 in-window (76b command center, 79, 80, 68, 69 audit with gaps filed as 81-86); 76c visual pass; Rob: "Great job so far".
- **S9 (2026-10-19, build):** 15 cards plus 73b and 74a; Rob rejected the look and gave samples; 76b and `mission-control.md` record the JARVIS design.
