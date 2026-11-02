# Plan 130: voice Cas is the PM

## What is unsafe, and the guard that makes each part safe

A brain with Bash, Edit, Write and Agent, no prompts, fed by a microphone, is the most dangerous thing on this machine: anyone audible, a video, a mishearing or text in a page it read can steer it. Outward and irreversible acts (deploys, client-site pushes, mail, deletes, billing) are safe only behind the go gate (cards 5, 6), a hook rather than the model's judgement. Rob hears the hook's summary of the real call, and only his next utterance counts: one exact phrase, heard with confidence, never while Cas is talking, one pending at a time, 120 s expiry, one use, a 2 s cancel window, all logged. The brain cannot forge a yes: server.py signs approvals with a `.key` file that credential-read-blocker refuses, and the fence (card 3) refuses tool writes to the gate's files. The remaining gap is an outward command missing from the list: commands the hook cannot read and writes to non-local hosts count as gated, and the audit (card 16) hunts for misses. Editing project code directly would skip that project's own hooks, so the fence holds the PM to its notes and work goes to loops or sessions started in the project (cards 11, 12). Answer-only and voice-guard assume typed text; card 4 adapts them once Rob approves (card 1). Today's voice brain is already unsafe: `--setting-sources project` keeps the global hooks off, so it can read a secret file aloud. Card 7 fixes that.

## Flags on the existing plan and rules

- PROCESS.md says loop-start refuses without a PM grant. `loop-start.cjs` has no such check; card 12 adds it.
- `D:\Dev\hq\.claude\settings.json` does not exist. HQ's project.md grants (`coordinator_only: off`, `window_merge: allowed`) reach only files inside `D:\Dev\hq`; the PM's reach came from coordinator-only failing open outside its project. Card 3's fence replaces that. Nothing else needs to move.
- Drop `--setting-sources project` in brain.py now, as a one-line fix. Until card 4, voice-guard may make Cas repeat a reply.
- `.gitignore`'s `bak-*/` misses `proto/bak110/`.
- Cards 2 and 13 touch `~/.claude` but no hook or loop code, so they are Small despite PROCESS.md's blanket Risky.

## Cards (in order)

### 1. Rob approves the go list and the spoken-question rule (waiting on Rob) (Small)
- Role: Rob, waiting on him. Risky path: no. Depends: none.
- Files:
  - `C:\Users\roban\.claude\pm\asks.md`

Needs a yes or an edit from Rob on three things. (a) Which actions need a spoken go: deploys (deploy scripts, wrangler, `gh workflow run`, ssh/scp/rsync to any host), a git push or PR merge to a client-site repo (henike-contracting and any repo whose push deploys), any mail or message send, recursive or forced deletes outside temp and `.claude/work` (rm -r, Remove-Item -Recurse, git branch -D, push --delete, reset --hard, clean -f, SQL DROP/DELETE/TRUNCATE), Infisical set/delete, billing, and POST/PUT/DELETE to any non-local host. (b) The go phrases: "go", "go ahead", "yes go", "permission granted". A bare "yes" is left out because it is too easy to say by accident. (c) In voice only, "can you X?" counts as a go-ahead and "what/why/is X?" stays a question; voice-guard logs in voice and does not block there.

Done-test: `findstr /c:"go list approved" C:\Users\roban\.claude\pm\asks.md` finds a line quoting Rob's yes and any edits. Card 5's policy file copies it.

### 2. windows-hide test covers cas-voice's Python (Small)
- Role: implementer, Sonnet, effort low. Risky path: no (a test only). Runs in the `~/.claude` repo. Depends: none.
- Files:
  - `C:\Users\roban\.claude\bin\windows-hide.test.cjs`

A second test scans `D:\Dev\cas-voice\proto\*.py`, skipping `bak*` folders. Every `subprocess.Popen/run/call/check_call/check_output` must pass `creationflags` naming `CREATE_NO_WINDOW`. `os.system` and `os.startfile` are refused outright. The test fails when the folder is missing, so moving it cannot silently drop the coverage.

Done-test: `node --test C:\Users\roban\.claude\bin\windows-hide.test.cjs` passes. New cases prove: a fixture `.py` with `subprocess.Popen(cmd)` is reported; one with `creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0)` is not; `os.system(` is reported; today's `proto/brain.py` scans clean.

### 3. Hooks know who the PM is; the PM's write fence (Risky)
- Role: implementer, Opus. Risky path: yes (`~/.claude/hooks`). Runs in the `~/.claude` repo. Depends: 2.
- Files:
  - `C:\Users\roban\.claude\hooks\pm-identity.cjs` (new)
  - `C:\Users\roban\.claude\hooks\pm-scope.cjs` (new)
  - `C:\Users\roban\.claude\hooks\pm-scope.test.cjs` (new)
  - `C:\Users\roban\.claude\hooks\credential-read-blocker.cjs`
  - `C:\Users\roban\.claude\hooks\credential-read-blocker.test.cjs`

`isPm(input, env)`: `CAS_PM=1` and the input's session_id equals `~/.claude/pm/pm-session.json`, which server.py writes. Until that file exists, a session whose cwd is `D:\Dev\hq` counts as the PM, so the HQ window keeps working; card 13 removes that fallback. `pm-scope.cjs` runs on PreToolUse for Edit, Write, MultiEdit, NotebookEdit and Bash. It does not apply to loop passes (`CLAUDE_LOOP_PASS=1`) or dispatched sessions (`CAS_PM_DISPATCH=1`). For the PM and its subagents, file writes are allowed only under `~/.claude/pm`, `D:\Dev\hq`, `c:\personas\cas`, any `<project>\.claude\work\` and `<project>\.claude\context.md`. In Bash it refuses builds, tests and installs (php tests/run.php, npm, npx, composer, pip, playwright) and anything that opens a window (`code`, `start`, `explorer`, `Start-Process`, `powershell`, `pwsh`, `cmd /c`, a `.cmd`, console `python.exe`). For every session, it refuses writes to `pm/conch.json` and `pm/grants/` unless `isPm`. Nobody may write `pm-session.json`, `go-pending.json`, `go-ledger.jsonl` or the go key through a tool. Nobody may set `CAS_PM`, `CAS_GO_GATE`, `CAS_PM_DISPATCH` or `LOOP_START_GRANT` inside a command. credential-read-blocker adds `voice-token` and the dashboard's `data/token`. Register with `node C:/Users/roban/.claude/bin/settings-hook-add.cjs PreToolUse "Edit|Write|MultiEdit|NotebookEdit|Bash" "node C:/Users/roban/.claude/hooks/pm-scope.cjs"`; Rob allowed that script as the way to register hooks on 2026-11-01, as its header records.

Done-test: `node --test C:\Users\roban\.claude\hooks\pm-scope.test.cjs C:\Users\roban\.claude\hooks\credential-read-blocker.test.cjs` passes. New cases prove: the PM's Edit of `D:\Dev\htdocs\henike-contracting\src\x.ts` exits 2, of `...\.claude\work\140\brief.md` exits 0 and of `~/.claude/pm/asks.md` exits 0. `CAS_PM=1` with the wrong session_id is not the PM, so its write to `conch.json` exits 2. Any session's Write or Bash naming `pm-session.json` or `go.key` exits 2. `CAS_PM=1 node loop-start.cjs` in a command exits 2. The PM running `php tests/run.php` or `code .` exits 2; with `CLAUDE_LOOP_PASS=1` the tests command exits 0. With no `pm-session.json`, cwd `D:\Dev\hq` is the PM. Reading `D:\Dev\cas-voice\proto\voice-token` is refused. The registration prints `added` or `already present`.

### 4. Two hooks that assume typed text adapt to speech (Risky)
- Role: implementer, Opus. Risky path: yes (`~/.claude/hooks`). Runs in the `~/.claude` repo. Depends: 1.
- Files:
  - `C:\Users\roban\.claude\hooks\answer-only-on-a-question.cjs`
  - `C:\Users\roban\.claude\hooks\answer-only-on-a-question.test.cjs`
  - `C:\Users\roban\.claude\hooks\voice-guard.cjs`
  - `C:\Users\roban\.claude\hooks\voice-guard.test.cjs` (new)

Only with `CAS_VOICE=1`, as Rob approved in card 1. answer-only judges the sentence's shape, not whisper's punctuation: "can/could/will you X", "go X", "please X" are go-aheads; what/why/how/is/are/did/should questions stay answer-only. voice-guard logs its hits but does not block, because audio already played cannot be taken back and a block makes Cas say a second reply. Typed sessions are unchanged.

Done-test: `node --test C:\Users\roban\.claude\hooks\answer-only-on-a-question.test.cjs C:\Users\roban\.claude\hooks\voice-guard.test.cjs` passes. New cases prove: with `CAS_VOICE=1`, "Can you go open henike and fix the footer?" allows a write and "What's running?" blocks one. Without `CAS_VOICE`, both block as today. voice-guard with `CAS_VOICE=1` returns `{}` on an em dash and appends a log line; without it, it still blocks.

### 5. Go gate: the policy file and the hook (Risky)
- Role: implementer, Opus. Risky path: yes (`~/.claude/hooks`, deploys). Runs in the `~/.claude` repo. Depends: 1, 3.
- Files:
  - `C:\Users\roban\.claude\pm\go-policy.json` (new)
  - `C:\Users\roban\.claude\hooks\go-gate.cjs` (new)
  - `C:\Users\roban\.claude\hooks\go-gate.test.cjs` (new)

`go-policy.json` holds card 1's approved rules, each with a spoken label, plus the go phrases, `key_path` (`%LOCALAPPDATA%\cas-voice\go.key`) and the ledger and pending paths. The hook is active only with `CAS_GO_GATE=1`, on PreToolUse for Bash, Edit, Write, MultiEdit and NotebookEdit. The digest is sha256 of the tool name, cwd and the call's working fields only (Bash `command`; file_path plus content or edits), so a changed `description` or `timeout` cannot break a retry. A call that matches a rule passes only on an approval line with a valid HMAC, unexpired, unused and matching its digest; the hook then marks it used. Otherwise it writes one pending entry (id, digest, a summary built from the real call such as "deploy kc-portal to WHC", expiry in 120 s) and exits 2 telling the brain to retry the identical call after Rob's go. A command it cannot read (`-EncodedCommand`, `iex`, eval, base64 piped into a shell) counts as gated. An unreadable policy refuses every gated tool. Register on `Bash|Edit|Write|MultiEdit|NotebookEdit` with settings-hook-add.cjs.

Done-test: `node --test C:\Users\roban\.claude\hooks\go-gate.test.cjs` passes. New cases prove, with `CAS_GO_GATE=1` and a temp policy, key and ledger: `bash deploy.sh` exits 2 and writes one pending with a 120 s expiry and summary; the same call with a valid approval exits 0 and marks it used; then the identical call exits 2 again; an approval with a wrong digest, past its expiry or with a bad MAC exits 2; the same Bash call with a different `description` matches the same approval; `git status` and `node loop-start.cjs` pass with no pending; `git push` in a henike-contracting fixture repo is gated; `powershell -EncodedCommand x` and `echo x | base64 -d | sh` exit 2; a missing policy refuses every Bash call; without `CAS_GO_GATE`, everything exits 0.

### 6. Go gate: the spoken go in cas-voice (Risky)
- Role: implementer, Opus. Risky path: yes (approves deploys). Runs in the cas-voice repo (`D:\Dev\cas-voice`). Never restarts the running engine. Depends: 5.
- Files:
  - `D:\Dev\cas-voice\proto\gogate.py` (new)
  - `D:\Dev\cas-voice\proto\test_gogate.py` (new)
  - `D:\Dev\cas-voice\proto\server.py`
  - `D:\Dev\cas-voice\.claude\project.md`

server.py watches `go-pending.json`. When a pending entry appears, server.py itself speaks "Say go and I'll <summary>.", so Rob hears the hook's words, not the model's. Only the next utterance counts; anything else drops the pending. A go is accepted only when: the whole utterance, minus punctuation and case, is one of the policy's go phrases; whisper's avg_logprob and no_speech_prob pass floors (`transcribe` returns them); `is_hallucination` is false; it was heard at least 0.5 s after Cas's audio ended, so Cas's own voice cannot count; exactly one pending is open and unexpired; Cas is awake. Then Cas reads back "<summary>, going", and any speech in the next 2 s cancels. After that, server.py appends an HMAC-signed approval and tells the brain "(go given, not typed by Rob: retry that exact call)". "no", "stop" or "cancel" drops the pending. Every outcome goes to the ledger with the heard words. The key is made on first start with `secrets.token_hex(32)` and never printed; it is not a master key (regenerating it only voids pending approvals). project.md's test_command adds `-m unittest discover -s proto -p "test_*.py"`.

Done-test: `venv\Scripts\python.exe -m unittest discover -s proto -p "test_*.py"` passes. New cases prove: with one pending, "go" and "Go ahead." write one approval whose MAC verifies and whose digest equals the pending's. Nothing is written for "no", "go home", "so", a bare "yes", a low-confidence "go", "go" after expiry, "go" with two pendings open, "go" while asleep, or "go" within 0.5 s of Cas's playback. Speech in the 2 s window cancels. Each outcome adds one ledger line. An end-to-end test runs `go-gate.cjs` with `CREATE_NO_WINDOW` and shows: a fixture deploy is refused; "go" is approved; the same call passes once, then is refused.

### 7. The voice brain gets the PM's tools with every hook firing (Risky)
- Role: implementer, Opus. Risky path: yes (starts `claude`). Runs in the cas-voice repo. Never restarts the running engine. Depends: 3, 4, 5, 6.
- Files:
  - `D:\Dev\cas-voice\proto\brain.py`
  - `D:\Dev\cas-voice\proto\server.py`
  - `D:\Dev\cas-voice\proto\test_pm_brain.py` (new)
  - `D:\Dev\cas-voice\proto\probe_pm.py` (new)

`build_cmd()` sets `--setting-sources user,project,local` so global and project hooks load, and `--permission-mode auto`, as claude-loop's headless passes use; never bypassPermissions. `--allowedTools` is `Read Grep Glob Bash Edit Write Agent SendMessage Skill WebSearch WebFetch TodoWrite`, `--disallowedTools` is `NotebookEdit`, `--strict-mcp-config` stays with no servers (no Chrome, no Gmail), and `--disable-slash-commands` goes so the coordinate and brief skills load. Default cwd becomes `D:\Dev\hq`. `Popen` keeps `CREATE_NO_WINDOW` and adds env `CAS_PM=1 CAS_VOICE=1 CAS_GO_GATE=1`. On the init event, server.py writes `pm-session.json` (session id and pid). The persona adds PROCESS.md, traps.md, `D:\Dev\hq\CLAUDE.md` and the open lines of asks.md; when one of those changes, the next turn starts with "(rules changed, not from Rob)" and the new text. VOICE_SECTION now says: act without asking, route and never build, say what was started. Each result's `permission_denials` count goes to the log. A PM verb that gets denied is added as a scoped `--allowedTools` rule.

Done-test: `venv\Scripts\python.exe -m unittest proto.test_pm_brain` passes, asserting the flags, env and cwd above, the persona contents, and the rules-changed prefix after an mtime bump. A live `venv\Scripts\python.exe proto\probe_pm.py` (Sonnet, effort low, its own brain, not the engine) shows: init `tools` contains Bash, Edit, Write, Agent, SendMessage and Skill; a turn running `git -C D:\Dev\hq status` and writing a scratch file ends with `permission_denials` empty; a Read of `C:\Users\roban\.claude\settings.json` is refused with `credential-read-blocker` text, which proves the global hooks load; `tasklist /v /fi "imagename eq claude.exe"` shows no window title.

### 8. Live PM state (Normal)
- Role: implementer, Sonnet. Risky path: no (reads through the existing `src/Running`, `src/Projects`). Runs in `_kc-dashboard`. Depends: none (reads card 10's log once it exists).
- Files:
  - `D:\Dev\htdocs\_kc-dashboard\src\Pm\PmState.php` (new)
  - `D:\Dev\htdocs\_kc-dashboard\bin\pm-state.php` (new)
  - `D:\Dev\htdocs\_kc-dashboard\tests\PmStateTest.php` (new)

`php bin/pm-state.php --out <file>` writes JSON with: open asks; the conch holder; loops (state, card, last log time, via LoopState over ProjectList); the queue (unpassed checklist cards); peer sessions (SessionRegistry status and idle minutes); the go pending; findings. The findings match the HQ check: `loop_stalled` (running, loop.log untouched 30 min); `card_oversized` (running past 20/60/120 min for Small/Normal/Risky, or a Small or Normal brief naming a risky path from its project.md); `chrome_without_conch` (a conch refusal in the last 12 min, from card 10's log); `idle_with_open_work` (idle or waiting 30 min or more in a project with an open ask).

Done-test: `c:\xampp\php\php.exe tests/run.php --filter PmState` passes. New fixture tests prove: a live lock with loop.log 31 min old is `loop_stalled` and 29 min is not; a Normal card at 61 min is `card_oversized`; a Small brief naming `src/Http/` is `card_oversized`; a 5-min-old conch refusal is `chrome_without_conch`; a 35-min idle session with an open ask is `idle_with_open_work`; CLOSED asks are left out; nothing outside the fixture roots is opened (`$opened`); the output contains nothing from a `.key`, token or settings file.

### 9. Heartbeat every 12 minutes, replacing the HQ cron (Normal)
- Role: implementer, Sonnet. Risky path: no. Runs in the cas-voice repo. Never restarts the running engine. Depends: 7, 8.
- Files:
  - `D:\Dev\cas-voice\proto\heartbeat.py` (new)
  - `D:\Dev\cas-voice\proto\test_heartbeat.py` (new)
  - `D:\Dev\cas-voice\proto\server.py`

Runs at :04, :16, :28, :40 and :52. It calls pm-state.php (`CREATE_NO_WINDOW`, output to a file) and keeps only findings new since the last check. When the brain is idle and Rob is not mid-turn, it sends one internal turn starting "(PM internal, not from Rob)"; the brain messages the offender, and its reply is never spoken. Anything that needs Rob goes to the cockpit as a `pm` line and is spoken at his next wake.

Done-test: `venv\Scripts\python.exe -m unittest proto.test_heartbeat` passes. Tests prove: the schedule never hits :00 or :30; the same finding twice gives one internal turn; an internal turn waits for a running Rob turn; internal replies never reach TTS; the subprocess passes `CREATE_NO_WINDOW`; a failed run is logged and retried at the next tick without raising.

### 10. Conch belongs to the voice PM; Chrome misuse is logged (Risky)
- Role: implementer, Opus. Risky path: yes (`~/.claude/hooks`). Runs in the `~/.claude` repo. Depends: 3.
- Files:
  - `C:\Users\roban\.claude\hooks\conch.cjs`
  - `C:\Users\roban\.claude\hooks\conch.test.cjs`

Each refusal appends `{at, cwd, session_id, tool}` to `~/.claude/pm/conch-refusals.jsonl` (`CONCH_LOG` overrides it for tests). The advice names the voice PM, not `D:\Dev\hq`. Card 3's fence keeps conch.json writes to the PM.

Done-test: `node --test C:\Users\roban\.claude\hooks\conch.test.cjs` passes. New cases prove: a refusal appends one line to a temp `CONCH_LOG`; the advice says "voice PM" and not "D:\\Dev\\hq"; the holder still passes; a missing conch.json still refuses.

### 11. Delegation stays headless; the voice turn stays responsive (Risky)
- Role: implementer, Opus. Risky path: yes (`~/.claude/bin`, starts `claude`). Runs in the `~/.claude` repo. Depends: 3, 7.
- Files:
  - `C:\Users\roban\.claude\bin\pm-dispatch.cjs` (new)
  - `C:\Users\roban\.claude\bin\pm-dispatch.test.cjs` (new)
  - `C:\Users\roban\.claude\hooks\pm-scope.cjs` (new in card 3)
  - `C:\Users\roban\.claude\hooks\pm-scope.test.cjs` (new in card 3)

`pm-dispatch.cjs <project> <brief>` is for one-line and research work; cards still go through loop-start. It starts `claude -p --permission-mode auto` detached, cwd set to the project so that project's hooks fire, `windowsHide: true`, env with `CAS_PM_DISPATCH=1` and `CAS_GO_GATE=1` and without `CAS_PM`. Output goes to `<project>\.claude\work\dispatch\<id>\out.jsonl`, and it returns at once. It refuses a brief outside the project's `.claude/work`. pm-scope adds two rules for the PM's main turn: an Agent call must use `run_in_background: true`, and a foreground Bash expected to run long is refused.

Done-test: `node --test C:\Users\roban\.claude\bin\pm-dispatch.test.cjs C:\Users\roban\.claude\hooks\pm-scope.test.cjs C:\Users\roban\.claude\bin\windows-hide.test.cjs` passes. New cases prove: the spawn has `detached`, `windowsHide: true`, cwd equal to the project, and env without `CAS_PM` but with `CAS_GO_GATE=1`; the call returns before the child exits; a brief outside `.claude/work` is refused; the PM's Agent call without `run_in_background` exits 2 and with it exits 0; a subagent's Agent call (with `agent_id`) passes.

### 12. loop-start needs a PM grant (Risky)
- Role: implementer, Opus. Risky path: yes (the loop). Runs in the `~/.claude` repo. Depends: 7, 11.
- Files:
  - `C:\Users\roban\.claude\bin\loop-start.cjs`
  - `C:\Users\roban\.claude\bin\loop-start.test.cjs`

Makes PROCESS.md's claim true. loop-start starts a loop when the caller has `CAS_PM=1`, when an unexpired `~/.claude/pm/grants/<project>.json` written by the PM exists, or when Rob has set `LOOP_START_GRANT=off`. Otherwise it refuses with "ask the voice PM". The loop's env drops `CAS_PM` and keeps `CAS_GO_GATE`. From here on, other windows need a grant to start a loop, as PROCESS.md intends.

Done-test: `node --test C:\Users\roban\.claude\bin\loop-start.test.cjs` passes. New cases prove: no grant means a refusal naming the voice PM and no spawn; `CAS_PM=1` spawns; an unexpired grant spawns and an expired one refuses; `LOOP_START_GRANT=off` spawns; the spawned env has no `CAS_PM`.

### 13. Retire the HQ window: the rules move to the voice PM (Small)
- Role: implementer, Sonnet, effort low. Risky path: no (docs, plus removing one fallback in `pm-identity.cjs`). `D:\Dev\hq` is not a git repo. Depends: 9, 10, 12.
- Files:
  - `C:\Users\roban\.claude\pm\PROCESS.md`
  - `D:\Dev\hq\CLAUDE.md`
  - `D:\Dev\hq\.claude\context.md`
  - `C:\Users\roban\.claude\hooks\pm-identity.cjs` (new in card 3)

"One PM" names the voice Cas (cas-voice brain, cwd `D:\Dev\hq`); the conch and outward-action sections point to the conch and go-gate hooks. HQ's CLAUDE.md says this folder is the voice PM's home and a window opened here is not the PM. context.md drops the CronCreate step. pm-identity drops the cwd fallback. The worker messages the HQ session to cancel its cron.

Done-test: `node --test C:\Users\roban\.claude\hooks\pm-scope.test.cjs` passes, with the fallback case now proving that cwd `D:\Dev\hq` without a matching session is not the PM. `findstr /c:"kc-dashboard window" C:\Users\roban\.claude\pm\PROCESS.md`, `findstr /c:"This window is the PM" D:\Dev\hq\CLAUDE.md` and `findstr /c:"CronCreate" D:\Dev\hq\.claude\context.md` each find nothing.

### 14. Live-check checker (Normal)
- Role: implementer, Sonnet. Risky path: no. Runs in the cas-voice repo. Depends: 7, 11.
- Files:
  - `D:\Dev\cas-voice\proto\livecheck.py` (new)
  - `D:\Dev\cas-voice\proto\test_livecheck.py` (new)

`livecheck.py --since <iso>` reads the engine log, the ledger and loop files, and samples `tasklist /v` every 2 s (`CREATE_NO_WINDOW`) while it runs.

Done-test: `venv\Scripts\python.exe -m unittest proto.test_livecheck` passes against fixture logs. It reports pass only when: the brain's results had zero `permission_denials`; a loop lock or dispatch folder appeared in the named project within 2 minutes; the deploy ask left a pending plus a `cancelled` line with Rob's "no" and no used approval; no claude, node, python, php, cmd or powershell process gained a window title.

### 15. Live check with Rob, then the HQ window closes (waiting on Rob) (Small)
- Role: Rob, with the PM running the checker. Risky path: no. Depends: 13, 14.
- Files:
  - `D:\Dev\cas-voice\proto\livecheck.py` (new in card 14)

Rob, in wake mode: "hey Cas, go open kc-dashboard and start the loop on card 123". Then "deploy kc-portal". Cas should say "say go and I'll deploy kc-portal..." and Rob answers "no". When the checker passes, Rob closes the HQ window.

Done-test: `venv\Scripts\python.exe proto\livecheck.py --since <start>` prints `PASS`, and asks.md gets the evidence line.

### 16. Security audit of the voice PM (Risky)
- Role: security-auditor, Opus, then a second-model pass. Risky path: yes (every card above). Depends: 15.
- Files:
  - `D:\Dev\htdocs\_kc-dashboard\.claude\work\130-voice-pm\audit.md` (new)

The auditor red-teams each of these and records the result: a forged or replayed approval; a "go" from Cas's own speakers; an obfuscated deploy; an inline `CAS_PM=`; reading `go.key` through `python -c`; writing `go-pending.json` with a shell redirect; prompt injection from a fetched page asking for a push; a `.cmd` or console python launch. Each miss becomes a failing test in the hook it beats, then a fix.

Done-test: `node --test C:\Users\roban\.claude\hooks\go-gate.test.cjs C:\Users\roban\.claude\hooks\pm-scope.test.cjs` and `venv\Scripts\python.exe -m unittest discover -s proto -p "test_*.py"` pass with the new red-team cases, and `findstr /c:"OPEN HIGH" D:\Dev\htdocs\_kc-dashboard\.claude\work\130-voice-pm\audit.md` finds nothing.
