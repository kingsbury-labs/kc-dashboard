---
session: 15
last_updated: 2026-11-01
continue_with: The PM moves to D:\Dev\hq (read D:\Dev\hq\.claude\context.md first). Dashboard work left - card 120 (mic VU meter, newest-first everywhere, autostart; brief in .claude/work/agentos/held/120-autostart-vu/), plus the Agenda gap, the clipped finance line and Care's empty right half, and a PM panel (asks, conch, live work).
blockers: Rob runs the Apache-as-service admin script once 120 lands; Rob's real "Hey Cas" mic test.
---

# KC Dashboard context

## To Resume
Session 15. main at b785dd2 on origin (kingsbury-labs/kc-dashboard). Tree carries only Rob's unrelated .claude/settings.json edit (leave it). The orchestrator (PM) no longer runs from this window: its home is D:\Dev\hq with its own context.md; this project is one of the projects it manages.
THIS WINDOW (if opened here for dashboard work):
1. Card 120 through the loop: move the held brief to `.claude/work/120-autostart-vu/`, add its plan.md entry, checklist, commit, `node C:\Users\roban\.claude\bin\loop-start.cjs D:/Dev/htdocs/_kc-dashboard --no-handoff --max-cards 1`.
2. One Small card for the three cockpit gaps above; desktop sizes (memory: cockpit-monitor-beside-face).
3. Prove every UI card with a headless Edge shot at 1080x1920 before showing Rob; never resize Chrome (Henike holds the conch).

## What exists
- Cockpit live at http://127.0.0.1:8787/cockpit.php, Chrome on portrait monitor 2. Cards 107-122 on main. 121 rebuilt the hero (Find moved to the rail, voice chip gone), 122 set desktop sizes. Care reads UptimeRobot correctly since bf4258d (custom_uptime_ratios).
- Local router experiment in `llm/router/` (never committed): Bonsai plus rules failed a blind 50-row test (8 of 16 Risky routed low), so it is not wired in. Record: `.claude/work/agentos/orchestration/ROUTER-MODEL.md`, `llm/router/verify/ROUTER-VERIFY.md`.
- Orchestrator design: `.claude/work/agentos/orchestration/DESIGN.md` (11 cards; the fast path cut it to a few files, see the hq context).
- Local models: Ollama on D:\ai\ollama\models; llama.cpp and Bonsai under `llm/` (used for mail tagging).

## Waiting on Rob
- Apache-as-service script after card 120; real "Hey Cas" mic test.
- Delete the `.premove` Docker disk once `D:\_logs\docker-move\status.json` says ok (move ran 2026-11-02 04:00).
- Issue 3 (follow-ups from cards 14 and 15), parked as Later.

## Decided
- The monitor sits beside Rob's face: desktop type sizes, never across-the-room sizes.
- The cockpit look is the codex-hub4 concept Rob picked, not the old JARVIS stills.
- Request sizing is the PM's judgment, rounded up; Risky is decided by each project's project.md risky paths at card time. No model router.
- Everything lives on D:. Loop files stay out of public repos.

## Recent sessions
- **S14 (2026-11-01, PM night):** fixed Care's UptimeRobot field, shipped cards 121 and 122 (hero, desktop sizes). Rob made this window PM over every session: Henike got its fly-in (a minifier turned 545ms into .545s), background, reviews and Services redesign fixed and shipped; the conch, inbox and settings-exception hooks went in (~/.claude 7f31443); PROCESS, asks and traps written in ~/.claude/pm; router bake-off run and dropped.
- **S13 (2026-11-01):** cards 107-119 built the live cockpit through the loop.
- **S12 (2026-11-01):** local model picked, Hermes dropped, Docker disk move scheduled.
