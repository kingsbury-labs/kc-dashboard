# 130. Plan: voice Cas is the PM (Risky)

## Objective
One file exists, `D:\Dev\htdocs\_kc-dashboard\.claude\work\130-voice-pm\plan.md`: ordered build cards that make the voice Cas (D:\Dev\cas-voice) the PM, each with a done-test a command can confirm, its files, its role and its size (Small, Normal or Risky). This card changes no code. Role: planner, Opus, high effort.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `C:\Users\roban\.claude\pm\traps.md`, `PROCESS.md`, `asks.md`; `D:\Dev\hq\CLAUDE.md`; `D:\Dev\hq\.claude\project.md`, `D:\Dev\hq\.claude\settings.json`
- `D:\Dev\cas-voice\proto\brain.py` (runs `claude` headless with CREATE_NO_WINDOW, read-only today) and the rest of `D:\Dev\cas-voice`
- `C:\Users\roban\.claude\hooks\` (every hook), `C:\Users\roban\.claude\settings.json`, the project `.claude/settings.json`
- `C:\Users\roban\.claude\bin\windows-hide.test.cjs`, `loop-start.cjs`, the conch code, the HQ PM check (every 12 min)
- Format to copy: the cards in `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md` (e.g. 125)

## Context
Rob, verbatim, the spec:

"the dashboard IS my PM, my orchestrator, my number-1, my VP. When I say, go open this thing and do X, it has to be able to do it - no prompts for permission, no ide windows open. Guardrails need to be really carefully set so that Cas can do whatever needs doing and delegate/route however it needs to without handholding. The exception is if it asks me specifically to say "give me permission to X" or "say go and I'll...", then that's ok."

cas-voice is at `D:\Dev\cas-voice` and is being put under git now; its cards run in that repo (say so in each such card). Verify every fact you cite by reading the file; the code below is not yet read by whoever briefed you.

The plan must cover:
1. The voice brain gets the PM's tools (Bash, Edit, Write, Agent, SendMessage, git, loop-start) with every existing hook still firing (`~/.claude/hooks` and project `.claude/settings.json` hooks) and no permission prompts. State exactly how: permission mode, allowlist, settings, cwd, and where its PM rules come from (PROCESS.md, asks.md, traps.md, D:\Dev\hq\CLAUDE.md). Nothing it starts opens a window (windowsHide; `windows-hide.test.cjs` must cover the new code).
2. The spoken-go gate. Cas acts without asking, except it asks "say go and I'll X" for outward or irreversible things (deploys, client emails, client-site pushes, deletes). The spoken yes is matched to that exact pending action, expires, and is logged, so a misheard word never triggers it. Which actions need it lives in one policy file, enforced by a hook.
3. Live PM state the voice answers from (asks, conch, running loops, card queue, peer sessions) and a heartbeat matching the HQ PM check every 12 min: loops stalled 30 min, oversized cards, Chrome use without the conch, idle sessions with open work.
4. Long work goes to loops and background agents headless; the voice turn never hand-codes and stays responsive.
5. Retiring the HQ window (`D:\Dev\hq`): the conch, the PM grants in `D:\Dev\hq\.claude\project.md`, and PROCESS.md's "One PM" rule move to the voice PM, and the hooks recognise it.
6. A live-check card: Rob says "go open X and do Y" and it happens with no prompt and no window.

At the top of plan.md put one plain paragraph flagging anything unsafe, and for each item say which guard makes it safe rather than watering the request down.

## Files you may touch
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\130-voice-pm\plan.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\130-voice-pm\progress.md`, `result.md`

## Done-test
`node -e "const s=require('fs').readFileSync('D:/Dev/htdocs/_kc-dashboard/.claude/work/130-voice-pm/plan.md','utf8');const c=s.split(/^### /m).slice(1);if(c.length<6||c.some(x=>!/Done-test:/.test(x)||!/Files:/.test(x)||!/Role:/.test(x)||!/\((Small|Normal|Risky)\)/.test(x.split('\n')[0])))process.exit(1)"` exits 0: at least six cards, each headed `### N. Title (Size)` with Role, Files and Done-test lines. Also, every file path in a Files line either exists or is marked new. No mutation check (no code).

## Boundaries
No commit, no push, no install, nothing outward. Edit no code or settings. Never run a command or read a file that prints a secret value. Do not start or stop the voice engine or Chrome. `project.md`'s Never list applies.

## Progress
Keep `progress.md` beside this file from your first step, one line per step that changes a file or finishes a unit of work. Already there? You are resuming: read it and check the disk first.

## Report
Write `result.md` beside this file, under 200 words: what plan.md holds, the unsafe items flagged, what was not verified.
