---
session: 16
last_updated: 2026-11-01
continue_with: The PM runs from D:\Dev\hq (read D:\Dev\hq\.claude\context.md first). Cockpit and voice are working; Rob is satisfied with voice. Next - voice behaviour tweaks when Rob names them, then a PM panel on the cockpit (asks, conch, live work).
blockers: none. Optional, Rob as admin when he likes: ops\install-apache-autostart.ps1 then node bin\install-autostart.cjs (dashboard and voice back by themselves after a reboot).
---

# KC Dashboard context

## To Resume
Session 16. main at 7fa3bd8 on origin (kingsbury-labs/kc-dashboard): this handoff; card 125 (9c53252) the last card landed. The PM (hq-ad, D:\Dev\hq) commits, pushes and starts loops; this window's guards refuse commit and push. The PM stashed Rob's .claude/settings.json Chrome grants ("pm: settings.json chrome grants") to pop later.
THIS WINDOW (if opened here for dashboard work):
1. Voice tweaks only when Rob names them. Engine settings live in D:\Dev\cas-voice\proto\server.py args (--min-silence-ms 600, --turn-p 0.7, --voice qwen, --ref wav\emma_en-gb.wav). Restart: stop the pythonw tree, then `node bin\cas-voice-start.cjs` (hidden, single-start guarded); ready in ~15-90 s, check with curl to 8766, never netstat (it misses listeners here).
2. PM panel card, sized by the PM.
3. Prove every UI card with a headless shot at 1080x1920 before showing Rob; never use Chrome (Henike holds the conch).

## What exists
- Cockpit live at http://127.0.0.1:8787/cockpit.php, Chrome on portrait monitor 2. Cards 107-125 on main: 120 autostart scripts, VU meter, newest-first lists; 123 cockpit gaps; 124 voice status (/api/voice.php, data/cas-voice.log, pythonw launcher); 125 launcher refuses a second start.
- Voice engine D:\Dev\cas-voice (NOT git; edits live only on disk). Voice: Qwen clone of Kokoro bf_emma (wav\emma_en-gb.wav + .txt). Wake matcher accepts whisper mishears and wake checks prompt "Hey Cas."; near misses logged (backups proto\bak-wake\). Brain persona (brain.py VOICE_SECTION): sarcastic, swears, plain Canadian English, never recites to-dos. Greetings in speech.py; short sentences paired after the first.
- Local router experiment in `llm/router/` (never committed), failed its blind test, not wired in. Orchestrator design: `.claude/work/agentos/orchestration/DESIGN.md`.
- Local models: Ollama on D:\ai\ollama\models; llama.cpp and Bonsai under `llm/` (mail tagging).

## Waiting on Rob
- Optional: the two autostart installs above.
- Docker disk move: D:\_logs\docker-move\status.json says preflight-ok (2026-11-01); delete the `.premove` disk only after it says ok.
- Issue 3 (follow-ups from cards 14 and 15), parked as Later.

## Decided
- The monitor sits beside Rob's face: desktop type sizes, never across-the-room sizes.
- The cockpit look is the codex-hub4 concept Rob picked.
- Request sizing is the PM's judgment, rounded up; no model router.
- Nobody starts the voice engine by hand or through cmd/run.cmd (it pops console windows); only bin\cas-voice-start.cjs.
- Everything lives on D:. Loop files stay out of public repos.

## Recent sessions
- **S15 (2026-11-01):** PM moved to D:\Dev\hq; this window ran dashboard and voice. Cards 120, 123, 124, 125 landed. Voice fixed end to end: engine was down, then the wake word was misheard (matcher widened, whisper prompted), the brain stopped reciting stale to-dos, launcher double-start found and guarded, voice moved to an expressive Qwen clone of Emma with a longer pause before answering. Rob: "all in all, I'm satisfied." Henike blog scheduler wired on Cloudflare (deploy hook as the worker secret, crons 11:00/12:00 UTC; not in Infisical, first real run 2026-11-02 11:00 UTC).
- **S14 (2026-11-01, PM night):** cards 121 and 122 (hero, desktop sizes), Care's UptimeRobot fix; this window became PM; Henike fixes shipped; conch, inbox and settings-exception hooks.
- **S13 (2026-11-01):** cards 107-119 built the live cockpit through the loop.
