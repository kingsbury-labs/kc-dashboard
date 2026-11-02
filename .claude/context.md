---
session: 15
last_updated: 2026-11-01
continue_with: The PM runs from D:\Dev\hq (read D:\Dev\hq\.claude\context.md first). Cards 120, 123 and 124 landed 2026-11-01 (autostart and VU meter, cockpit gaps, voice engine status). The voice is now wav\aud_brit_warm.wav (server.py --ref default). Left - a PM panel (asks, conch, live work).
blockers: none. Optional, Rob as admin when he likes: ops\install-apache-autostart.ps1 then node bin\install-autostart.cjs (dashboard back by itself after a reboot).
---

# KC Dashboard context

## To Resume
Session 15. main at 1ac8b4e on origin (kingsbury-labs/kc-dashboard); card 124 the last card landed, 1ac8b4e the PM's context commit. The PM (hq-ad, D:\Dev\hq) commits, pushes and starts loops; this window's guards refuse commit and push. The PM stashed Rob's .claude/settings.json Chrome grants ("pm: settings.json chrome grants") to pop later.
THIS WINDOW (if opened here for dashboard work):
1. Voice: Rob picked aud_brit_warm (server.py --ref default); the brain speaks plain Canadian English, swearing allowed (brain.py VOICE_SECTION). Engine starts only through card 124's launcher; nobody starts it by hand (it pops console windows).
2. cas-voice is not git: the wake matcher fix (speech.py, server.py, backups in proto\bak-wake\) and the voice persona edit (brain.py) live only on disk.
3. Prove every UI card with a headless shot at 1080x1920 before showing Rob; never use Chrome (Henike holds the conch).

## What exists
- Cockpit live at http://127.0.0.1:8787/cockpit.php, Chrome on portrait monitor 2. Cards 107-122 on main. 121 rebuilt the hero (Find moved to the rail, voice chip gone), 122 set desktop sizes. Care reads UptimeRobot correctly since bf4258d (custom_uptime_ratios).
- Local router experiment in `llm/router/` (never committed): Bonsai plus rules failed a blind 50-row test (8 of 16 Risky routed low), so it is not wired in. Record: `.claude/work/agentos/orchestration/ROUTER-MODEL.md`, `llm/router/verify/ROUTER-VERIFY.md`.
- Orchestrator design: `.claude/work/agentos/orchestration/DESIGN.md` (11 cards; the fast path cut it to a few files, see the hq context).
- Local models: Ollama on D:\ai\ollama\models; llama.cpp and Bonsai under `llm/` (used for mail tagging).

## Waiting on Rob
- Optional: the two autostart installs above (card 120 replaced the old Apache-as-service script). "Hey Cas" wakes since the 2026-11-01 matcher fix.
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
