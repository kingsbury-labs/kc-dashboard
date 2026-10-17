---
session: 6
last_updated: 2026-10-17
continue_with: Read .claude/work/attention-engine.md, then re-plan cards 60-69 with Rob around it (mail and gpt-oss dropped, facts table and rule collectors first). Copy the loop into this project's .claude/ before the next run. Do not start the loop on the old cards.
blockers: Rob decides whether to lift the no-API rule for scheduled jobs, and approves the spec/plan changes in attention-engine.md.
---

# KC Dashboard context

## To Resume
2026-10-13: READ `.claude/work/attention-engine.md` FIRST. A planning session redesigned the model side (mail dropped, gpt-oss dropped, an "attention engine" replaces impact tags as the main feature). The card list below predates it and needs re-planning with Rob before the loop runs again. Project paths moved to `D:\Dev\htdocs`.

Session 7. main on origin (kingsbury-labs/kc-dashboard, public) ends with the session 6 commit (D: move plus the attention-engine planning files).
THIS WINDOW:
1. Read `.claude/work/attention-engine.md` and its three reviews in `.claude/work/reviews/attention-*.md`.
2. Get Rob's calls on the two blockers above, then re-plan cards 60-69 in `plan.md` around them. Do not run the loop before the re-plan.
3. Copy the loop files into this project's `.claude/` (global rule 7, 2026-10-16); there is no `.claude/bin` here yet.
4. Then start the loop from the project's own copy with `--merge-allowed`.
Apache is live on D: (8787 serves, port 80 denies the folder). `php ops/http-check.php` fails one known check (token on `/` outside its meta tag), owned by the UI card.

## What exists
- On main: 01 test runner, 04 spec, 05 logger, 06 vhost snippet and HTTP check, 10 request guard and token, 11 responses and front controller, 12 job queue, 13 worker and registry, 13a runner fails on broken test files, 14 safe directory check, 15 ProcessRunner. Done outside the loop: 02 decisions, 03 models on disk, 07 vhost installed, 09 fallback = gpt-oss:20b.
- Live: Apache is loopback-only (127.0.0.1 and ::1), `.credentials` denied, project folder 403 on port 80, dashboard served on http://127.0.0.1:8787/ from `public/`. Config backups: `C:\xampp\apache\conf\*.bak-2026-10-12*`.
- Models: PrismML llama.cpp fork `prism-b10772` in `llm/fork`, Ternary-Bonsai-2-27B PTQ1_0 in `llm/models` (gitignored). Bench: 20/20 valid JSON, about 1.4 s per headline, 46 tok/s, 7.6 of 8.1 GB VRAM, thinking off. Flags: `-ngl 99 -fa on -c 8192 --cache-type-k q8_0 --cache-type-v q8_0 --jinja`, port 8081.
- Plan: `.claude/work/plan.md` (cards 60-69 now, Waiting on Rob, Later). State: `checklist.json`. Notes: `log.md`. Reviews: `.claude/work/reviews/`.
- Remotes: `origin` public kingsbury-labs repo (squashed to one root commit for release); `private-archive` the old private repo with full history.
- `.claude/loop-settings.json` allows the project test command, the shared runner and edits under src/, tests/, public/, bin/ headless (Rob approved).

## Decided
- Single-user tool: remaining work collapsed into 10 cards; terminal button, weekly note, model update check, health strip, SSL and loop status deferred to Later.
- Loop runs use `--merge-allowed`; cards land straight on main (ci: none).
- Bonsai is primary; gpt-oss:20b is the fallback (CPU-heavy, only when Bonsai is down). One model in VRAM at a time.
- Issue 3 keeps the ProcessRunner follow-ups that went to Later.

## Recent sessions
- **S5 (2026-10-12, interactive):** re-planned 54 cards into 60-69; installed the 8787 vhost (card 07), closing port-80 access to bin/ and tests/; loop landed 13a, 14, 15 before stopping on STOP.
- **S4 (2026-10-12, loop run):** cards 13a, 14 and 15 passed the gate and pushed to main. Issues 1-2 closed, issue 3 filed for card 14-15 follow-ups.
- **S3 (2026-10-12, loop run):** cards 05, 10-13 pushed to main. Runner passing on a parse error stopped the loop.
