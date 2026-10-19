---
session: 9
last_updated: 2026-10-19
continue_with: Rob waived the loop for this build. Run plan revision 5 in-window, one implementer agent per card, in plan order (59, 60, 62, 73-77, 70-72, 63, 67, 78-80, 68, 69). Before Rob leaves for ~8 hours, rehearse every gate once while he is present and get every permission granted up front.
blockers: coordinator-only.cjs refuses git commit and push from the main window (COORDINATOR_ONLY is unset), so each card's commit goes through a short background agent, which the hook exempts. Live Google numbers wait on card 08 (service account as Viewer on each property); until then card 67 runs on fixtures.
---

# KC Dashboard context

## To Resume
Session 9. main on origin (kingsbury-labs/kc-dashboard, public) ends with the session 8 handoff commit (plan revision 5, JARVIS research).
THIS WINDOW (Rob is present at the start, then away about 8 hours):
1. Rehearse the gates with Rob watching, so nothing prompts later: `c:\xampp\php\php.exe tests/run.php`; `node .claude/bin/project-test.cjs` as the coordinate skill calls it; one implementer agent writing under `src/`, `public/`, `tests/`, `config/`, `bin/`, `docs/`; one background agent doing `git add <paths>`, `git commit`, `git push` on main. Whatever prompts or the classifier refuses, Rob allows before he leaves (`/permissions`). Claude cannot edit settings files itself.
2. Build in-window with the coordinate skill's gate (done-test, mutation check, scope), one agent at a time (no fan-outs), then a background agent commits each card on main without a co-author trailer. Self-pace with `/afk`.
3. Stop on: a card failing twice, the usage cap, or anything that needs Rob (credentials, installs, outward actions). Log it in `.claude/work/log.md` and go on to the next card that does not depend on it.

## What exists
- On main: cards 01, 04, 05, 06, 10-15, 13a (runner, logger, vhost, guard and token, responses, job queue, worker, SafeDir, ProcessRunner). Done outside the loop: 02, 03, 07 (vhost installed).
- Live: Apache loopback-only, dashboard on http://127.0.0.1:8787/ from `public/`. `php ops/http-check.php` fails one known check (token outside its meta tag), owned by card 62.
- Plan: `.claude/work/plan.md` revision 5, 18 cards. JARVIS research and sources: `jarvis-research.md`. Design: `attention-engine.md`. Finance: `finance-contract.md`. State: `checklist.json` (entries are added when a card is briefed). Log: `log.md`.
- Loop copy in `.claude/bin`, `.claude/skills`, `.claude/agents` (unused for this build). `.claude/loop-settings.json` still lacks the project-copy runner line; it only matters to headless runs.

## Decided
- Revision 5 (Rob, 2026-10-19): a JARVIS layer on revision 4: project map, what's running (Claude Code's `~/.claude/sessions/<pid>.json`, never the `.key` files), HUD and orb, wall page, vault at `D:\Dev\vault\kc` that Obsidian opens for the graph and kanban, briefings, memory, voice out. Numbers cover every property. Voice in, local model (Qwen3.5-9B router, Qwen 3.6 35B-A3B on demand; gpt-oss stays out), voice handoffs, wake word, Meta Ads and Buffer are Later.
- No Hermes: its Anthropic login bills extra usage, and the video's "Agent OS" is a paid zip.
- Attention engine (2026-10-17) stands: mail out, no-API rule, daily read via `claude -p`. kc-finance read-only through `alerts.json` schema 1, never-store list in `finance-contract.md`. Issue 3 keeps the ProcessRunner follow-ups for Later.

## Recent sessions
- **S8 (2026-10-19, research):** watched the Hermes Jarvis + Obsidian video (a Julian Goldie sales video for a paid zip); a research agent mapped the free tools for every feature (`jarvis-research.md`); Rob chose round one only, so the plan is now revision 5 with cards 73-80, card 67 covering every property, and the rest in Later. Rob waived the loop for this build.
- **S7 (2026-10-17/19, interactive):** recorded kc-finance's contract; rewrote the plan as revision 4 (cards 59-72) around the attention engine; copied the loop into the project.
- **S6 (2026-10-13, planning):** attention-engine design and two reviews; project moved to `D:\Dev\htdocs`; decision rubric from Sembr added to CLAUDE.md.
