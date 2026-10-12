---
session: 5
last_updated: 2026-10-12
continue_with: Start claude-loop fresh at card 60 (Ask Claude core) with --merge-allowed; it runs cards 60-69 in order. Card 08 credentials whenever Rob has them; mail cards park until then.
blockers: Card 08 (mail credentials, file names only) waits on Rob. Cards 28 (load extension) and 52 (register scheduled tasks) wait on Rob after 68 lands.
---

# KC Dashboard context

## To Resume
Session 6. main on origin (kingsbury-labs/kc-dashboard, public) ends with the session 5 handoff commit.
THIS WINDOW:
1. Start the loop (no STOP file present):
   `node C:\Users\roban\.claude\bin\claude-loop.cjs --project C:\xampp\htdocs\_kc-dashboard --max-cards 15 --afk-flags "--merge-allowed"`
2. Next card is 60 Ask Claude core. Cards 60-69 are feature-sized; about an hour of loop time.
3. Card 62's done-test includes `php ops/http-check.php` exiting 0 (one check fails today: the token shows on `/` outside its meta tag).

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
