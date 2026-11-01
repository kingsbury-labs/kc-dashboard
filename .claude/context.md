---
session: 14
last_updated: 2026-11-01
continue_with: Read .claude/work/agentos/HANDOFF-2026-11-01-s13.md. Screenshot the live cockpit at Rob's real Chrome window on monitor 2; loose layout (Voice-is-off chip on the search edge, hero weather wrap, Find box, kc-finance dash rows, any overlap) becomes card 121; then card 120 (brief held in .claude/work/agentos/held/120-autostart-vu/): VU meter, newest-first order everywhere, autostart after reboot.
blockers: Rob runs the Apache-as-service admin script once 120 lands; Rob's real "Hey Cas" mic test.
---

# KC Dashboard context

## To Resume
Session 14. Session 13 built the cockpit live at http://127.0.0.1:8787/cockpit.php through the loop: cards 107-119 on main (last 0c3b239), all gated and audited. Full state, keys, standing orders and the ordered next steps are in `.claude/work/agentos/HANDOFF-2026-11-01-s13.md`; read it first. The plan 6 re-plan below is superseded by that work.

## Session 13 (superseded notes kept below)
main on origin (kingsbury-labs/kc-dashboard, public) at the session 12 handoff merge. Loop files and `.claude/work/` are untracked on purpose (8b933c9; `.git/info/exclude`). Issue 3 open (Later).
FIRST, if before noon on 2026-11-02: read `D:\_logs\docker-move\status.json` (the 04:00 Docker disk move, below). If `ok`, check Bigcapital answers, then ask Rob before deleting `C:\Users\roban\AppData\Local\Docker\wsl\disk\docker_data.vhdx.premove` (54 GB).
THIS WINDOW:
1. A Small background agent commits `docs/AGENTOS.md` (one line: loops start from the global `claude-loop.cjs` in the project) so the tree is clean.
2. Re-plan plan 6 as one loop card (the `loop-only-cards` hook refuses a planner agent in-window): brief it with the `brief` skill, size Risky (global R4, R5, R7), output `.claude/work/agentos/replan/plan6-replan.md`, no edits to plan.md, checklist.json or AGENTOS.md until Rob reads it.
3. Rob approves; fold into `.claude/work/plan.md`, `checklist.json` and `docs/AGENTOS.md`, then phase 1a (1.1 guard, 1.2 index, 1.3 live, 1.10 logon, R1) runs; 1.4 skin waits on Rob's look pick.

## What exists
- Dashboard (plan 5.1) unchanged since S10: live at http://127.0.0.1:8787/ from `public/`, suite 399 at last count.
- Cockpit work under `.claude/work/agentos/`: research, speedup, data-spike, `ux/ux-direction.md`, prototypes v2 (rejected) and v3 (fake data, see Decided), `replan/` (PROPOSAL.md with 2026-11-01 decisions, R1-R5 briefs). Spec `docs/AGENTOS.md`; plan 6 cards 87-106 in `.claude/work/plan.md`.
- Look references: `design-refs/agentos-jarvis/refs.md` (14 refs, top 5 plus Rob's Instagram reel pick), older JARVIS stills in `design-refs/agentos/`. Both gitignored.
- Docker disk move: scheduled task `KC\DockerDiskMove`, 2026-11-02 04:00, script `D:\Dev\ops\docker-move\move.cjs` (preflight passed; rolls back to C: on any failed check; log and status in `D:\_logs\docker-move\`). kc-finance and SparrowBook were told through their project memory; live windows by message.
- Local models: Ollama on D:\ai\ollama\models with `qwen3.5:9b-q4_K_M` (native tool calls pass at 64k, 20 tok/s, 30% on CPU on the 8 GB RTX 5060), gemma4:12b, gpt-oss:20b. User env has OLLAMA_FLASH_ATTENTION=1 and OLLAMA_KV_CACHE_TYPE=q8_0. For real local work later, llama-server from `llm/` with `--n-cpu-moe` (Qwen3.6 35B-A3B about 33 tok/s on 8 GB per XDA).

## Waiting on Rob
- The re-plan output, then spec approval (card 87). A look pick from refs.md for 1.4.
- Delete the `.premove` Docker disk after the move checks out.
- Max plan for November (governor thresholds stay config until known).
- CI host watchdog (WSL idle and Docker down); loop-start.cjs against closing VS Code; Stripe key rotation then `secret-leak-scan.cjs --ack`; carried S10 items (card 83 finance evidence, `claude -p` canary, owed mutation runs, php.ini timezone, cards 08, 28, 52, 81-86 unbriefed).
- kc-finance still lives on C: (`C:\Users\roban\kc-finance`), the one project not on D:.

## Decided
- Cockpit: desktop first, loopback only, workers are real `claude` sessions on Rob's Max login, no API, agents never send email. Re-plan order (PROPOSAL.md): daily value first, GPU-heavy visuals last, skin stays in phase 1.
- Section 12 exception for R1: yes (scrubbed, 2,000-character cap, three named sources).
- R7 resource leases (Rob, 2026-11-01): every window, agent and process coordinates the browser, GPU, WHC deploys and `~/.claude` automatically through a global hook and a lease board, not by chat. Until it ships, windows announce to each other by message before using those (agreed with kingsburycreative-fa and the Henike windows).
- Galaxy (Rob's v3 review): every project shown; motion and light only for real activity; links light when work or a lease passes between projects; zoom in to see what is active. v3's random motion over fake data was the rejected part.
- Hermes Workspace: installed, toured, rejected as plain ("meh"), removed with its images. No Hermes, no paid review tools; reviews come later from an Opus reviewer pass at the gate (not built).
- Everything lives on D:. Loop files stay out of public repos.

## Recent sessions
- **S12 (2026-11-01, look and housekeeping):** picked up the claude.ai handoff. Picked and tested a local model, ran and dropped Hermes, cleared about 14 GB of Docker images, stale permissions out of shared settings. Rob added R7 leases and the galaxy rule; section 12 yes. Coordinated live with the kingsburycreative, kc-portal and Henike windows. Scheduled the unattended Docker disk move. Re-plan blocked in-window by the loop-only-cards hook, now the next card.
- **S11 (2026-10-23 to 25, Agent OS and loop speed):** cockpit spec and plan 6, loop fixes 01-10 carried to every copy, v2 rejected, v3 built.
- **S10 (2026-10-19, build):** finished plan 5.1 (command center, audit gaps filed as 81-86); Rob: "Great job so far".
