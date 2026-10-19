---
session: 8
last_updated: 2026-10-19
continue_with: Add the local runner and docs/config lines to .claude/loop-settings.json (Rob; the classifier refused it), then start the loop from the project copy with --merge-allowed on plan revision 4, card 59 first. Check kc-finance's loop lock is gone first.
blockers: .claude/loop-settings.json still pre-approves only the global project-test.cjs; headless passes now run the project's own copy and may be refused at the gate until Rob adds those lines.
---

# KC Dashboard context

## To Resume
Session 8. main on origin (kingsbury-labs/kc-dashboard, public) ends with the session 7 handoff commit (plan revision 4, finance contract, project copy of the loop).
THIS WINDOW:
1. Rob adds to `allow` in `.claude/loop-settings.json`: `Bash(node D:\\Dev\\htdocs\\_kc-dashboard\\.claude\\bin\\project-test.cjs:*)`, the `D:/...` and PowerShell forms of it, and `Edit`/`Write` for `docs/**` and `config/**`. The classifier refuses this edit from Claude (self-modification).
2. Confirm no live `C:\Users\roban\kc-finance\.claude\work\loop\lock` without a STOP (one loop at a time).
3. Start the loop: `node .claude/bin/claude-loop.cjs --afk-flags "--merge-allowed"`. Cards run in plan order: 59, 60, 62, 70, 71, 72, 63, 67, 68, then 69.

## What exists
- On main: cards 01, 04, 05, 06, 10-15, 13a (runner, logger, vhost, guard and token, responses, job queue, worker, SafeDir, ProcessRunner). Done outside the loop: 02, 03, 07 (vhost installed).
- Live: Apache loopback-only, dashboard on http://127.0.0.1:8787/ from `public/`. `php ops/http-check.php` fails one known check (token outside its meta tag), owned by card 62.
- Plan: `.claude/work/plan.md` revision 4. Design: `attention-engine.md`. Finance: `finance-contract.md`. State: `checklist.json`. Log: `log.md`.
- Loop: project's own copy in `.claude/bin`, `.claude/skills` (coordinate, brief, afk), `.claude/agents`; `SOURCE.txt` holds the source hashes. The card prompt in `claude-loop.cjs` reads `.claude/skills/coordinate/SKILL.md` directly instead of `/coordinate`, so changes stay local; its tests pass.
- Bonsai still in `llm/` (gitignored); off the critical path now.

## Decided
- Rob approved the attention-engine spec and plan changes (2026-10-17). Mail out entirely; gpt-oss out; Bonsai and impact tags to Later; no-API rule stays, so the daily read is `claude -p`.
- kc-finance is read-only through `C:\Users\roban\kc-finance\alerts\alerts.json` (schema 1). Never stores credentials, account numbers, bank lines, receipts or personal income. Ask Claude reaches kc-finance through an exact extra-folder list, shows "finance busy, try later" while its `scripts/` is dirty, and sets `MSYS_NO_PATHCONV=1`.
- Loop runs use `--merge-allowed`; cards land on main (ci: none). Issue 3 keeps the ProcessRunner follow-ups for Later.

## Recent sessions
- **S7 (2026-10-17/19, interactive):** asked kc-finance how it should feed the dashboard and recorded its contract; rewrote the plan as revision 4 (cards 59-72) around the attention engine; copied the loop into the project and made passes read the local skills. Loop not started: kc-finance's loop was running, the loop-settings edit was refused, and coordinator-only blocked the commit until Rob turned it off.
- **S6 (2026-10-13, planning):** attention-engine design and two reviews; project moved to `D:\Dev\htdocs`; decision rubric from Sembr added to CLAUDE.md.
- **S5 (2026-10-12, interactive):** re-planned 54 cards into 60-69; installed the 8787 vhost (card 07); loop landed 13a, 14, 15.
