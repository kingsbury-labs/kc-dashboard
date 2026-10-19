# AFK

The user is leaving. Switch to autonomous mode: keep working using the project rubric, stop asking questions, and self-pace across turns until the user returns.

## When to Use

Invoke when the user types `/afk` (or says they are stepping away / going AFK and want you to keep working). It flips you from interactive into autonomous mode for the rest of the work in flight AND keeps you working across turns until the user comes back.

`/afk` accepts optional flags that pre-authorize specific behaviors for this session (see **Flags** below). With no flags, the conservative defaults apply: never merge, never ask (pick the reversible option and keep going), park every outward/irreversible action.

The user ends AFK mode by saying something like "I'm back", "back", or "resume". On any such message, exit autonomous mode (see Resume below).

## FIRST — is this a real `/afk`? (stray-wakeup guard)

**Check this before doing anything else, every time this skill runs.**

This skill self-paces with `ScheduleWakeup`, a one-shot timer that re-injects this exact `/afk` prompt when it fires. **There is no tool to cancel a pending wakeup** — `CronDelete` only removes `CronCreate` jobs, and a `ScheduleWakeup` does not appear in `CronList`. So a wakeup armed during an earlier AFK stretch can fire AFTER the user has returned and re-inject `/afk`, looking exactly as if the user just typed it. They did not.

Before entering AFK mode, confirm this is a fresh, real, user-typed `/afk`:

- If AFK was active earlier and the user has since signalled return ("I'm back" / "back" / "resume"), handed off, or sent ANY normal interactive message (a question, a directive — anything that is not them typing `/afk`), then **AFK has already ended.** A `/afk` arriving now is real ONLY if the user's most recent input *is* the `/afk` invocation itself.
- If `/afk` appeared on its own — the user's last actual message was something else and this `/afk` was injected without them typing it — it is a **STRAY scheduled-wakeup firing.**

**On a stray firing: do NOT enter AFK mode. Do NOT work, merge, post, or schedule. Do NOT call `ScheduleWakeup`.** Reply in one line that a leftover scheduled wakeup fired and was ignored, then stop. Declining to re-arm is what breaks the loop — no reschedule means no further firings.

When genuinely unsure whether the user invoked it, ASK in one line ("a scheduled `/afk` wakeup fired — did you mean to go AFK?") rather than silently entering autonomous mode. Falsely entering AFK while the user is present is the exact failure this guard prevents.

## Flags

Flags are explicit, per-session grants the user types on the `/afk` line. They are the ONLY way to relax a default — you can never self-grant any of these mid-session. Parse the flags from the invocation, then **echo back which flags are active in your first AFK response** so the grant is on the record. An unrecognized flag: ignore it and note it in that first response; do not guess its meaning.

| Flag | Default (no flag) | With flag |
|------|-------------------|-----------|
| `--merge-allowed` | Open the PR, run `gh pr checks`, then STOP before merging. | After `gh pr checks --required` is green, merge to main with `--merge --delete-branch` (never squash: a squash concatenates every commit message, and one reading "Does not close #1629" closed #1629), then confirm with `git fetch && git log --oneline -3 origin/main`. Still PR-only (never direct push to `main`), still never `--auto` (it silently no-ops on this repo). A red/pending check still parks the merge. |
| `--stop-with-questions` | On a genuine rubric toss-up, pick the most defensible reversible option, log it, keep moving. | On a genuine rubric toss-up, STOP and wait: write the question to the AFK log, stop self-scheduling, and hold for the user instead of deciding. Clear-cut decisions still proceed; this only changes true forks. |
| `--allow-outward <list>` | Every outward/external action (deploy, email, open GitHub issues, close GitHub issues, post comments, etc.) parks. | The specific actions named in the comma-separated `<list>` are pre-authorized (e.g. `--allow-outward deploy,close-issues`). Everything NOT named still parks. Match names loosely but conservatively: if an action is not clearly covered by a listed name, park it. |
| `--allow-test-db-reset` | `sembr:reset-test-db` parks, because dropping and recreating a database sits on the destructive line and the floor says park anything on the line. | Run `DB_DATABASE=sembr_test c:/php84/php.exe artisan sembr:reset-test-db` when the local test database is broken. NOTHING ELSE. See the note below for the exact boundary. |

**Recognized `--allow-outward` values** (this is a list of values, NOT of flags):
`open-issues`, `close-issues`, `comment-issues`, `deploy`, `email`, `dispatch-workflow`.
There is no bare `--open-issues` or `--close-issues` flag and there never was.
If the user types one, say so in the first response and treat the grant as absent
rather than guessing they meant the `--allow-outward` value. Filing a GitHub
issue is an outward action and parks without `open-issues` in the list, including
at session wrap-up and including when a project's own CLAUDE.md tells you to file
one; the flag grant is narrower than the project rule and the narrower one wins.

Flags compose. `/afk --merge-allowed --stop-with-questions` means: merge green PRs, but stop on true toss-ups.

### `--allow-test-db-reset`, and why it is its own flag

**Sembr only.** The command it permits exists only in Sembr. In any other
project, treat the flag as unrecognized: say so in the first response and grant
nothing.

It is not an `--allow-outward` value, because nothing about it is outward. It is
a single local command that reaches through the destructive floor, so it gets its
own narrow grant rather than widening a list that means something else.

**What it permits: one command.** `sembr:reset-test-db`, and only when the local
test database is genuinely broken — a half-applied migration, a schema that no
longer matches `database/migrations`, a suite failing on a missing relation. Not
as a first move, not to tidy up, not because a test is red for some other reason.
Diagnose first; the command is the remedy for a specific diagnosis.

**Why it is safe enough to grant while nothing else on that floor is.** The
command refuses to run outside the `local` and `testing` environments, and its
`ALLOWED_DATABASES` constant admits only `sembr_test` and `testing` — two gates in
the command itself, not in this prose. The second gate reads the database name off
the LIVE connection rather than off config, so pointing a connection somewhere
unexpected does not get past it. The data it destroys is fixture data that
`migrate:fresh` regenerates, and CI builds the same database from scratch on every
run. There is nothing in it to lose.

**Why it is worth granting at all.** Session 338 hit exactly this: a grant
migration referenced a column that did not exist yet, every fresh database died
part-way, and the suite could not run until the database was rebuilt. Without this
flag an AFK session in that position parks, and parking there stalls everything
downstream, because no PR can be verified without a working suite. The rebuild is
also what PROVED the fix, so it is a verification step, not just a repair.

**The floor is otherwise untouched.** This flag reaches `sembr:reset-test-db` and
nothing adjacent: not `migrate:fresh` by hand, not the local tenant fleet reseed
(`sembr:seed-local-test`, which destroys demo data nobody regenerates
automatically), not any drop, truncate or bulk delete, and nothing that touches a
database other than the two named above. If an action is merely similar to this
one, it parks.

### The destructive floor (one flag reaches it; the rest cannot)

Genuinely destructive or unrecoverable actions park, and `--merge-allowed`, `--stop-with-questions` and `--allow-outward` cannot change that: deleting data, dropping tables/columns, `git push --force`, resetting or rewriting git history, bulk deletes/updates, anything that loses data irreversibly. `--allow-outward` loosens *reversible outward* actions only; it never reaches this floor.

**One flag reaches through it, and only at one point.** `--allow-test-db-reset` permits `sembr:reset-test-db` against the local `sembr_test` / `testing` databases, whose contents are fixtures regenerated by the command itself. That is the entire exception. It is written here as well as in the flag table so a session reading the floor does not park something the user explicitly granted; every other destructive action still parks with any flag set. If a requested action sits on the line, treat it as destructive and park it. The hero remains LOCKED (no flag in this skill unlocks it).

## What AFK mode means

While AFK:

1. **Do not ask the user anything.** No `AskUserQuestion`, no "should I proceed?", no clarifying questions, no waiting for confirmation on routine work. The user is not at the keyboard. (Exception: with `--stop-with-questions`, a genuine rubric toss-up stops and waits instead of being decided — see item 3 and the Flags table.)

2. **Decide with the rubric.** Wherever you would normally ask, resolve it yourself using the project rubric, in order:
   `consensus > security > correct-long-term > best-practices > project health > tenant/client UX`
   (or the project's own rubric, if its `CLAUDE.md` names one)
   (No time/budget framing — that is not a tiebreaker. See the `feedback_no_time_pressure` and `feedback_decision_principle` memories.)

3. **Genuine toss-ups: pick, log, keep moving** (default). If the rubric does not clearly resolve a fork, choose the most defensible option, **favoring the reversible one**, write the decision and the reasoning to the AFK log (below), and continue. Do not park work waiting for the user. **With `--stop-with-questions`:** instead of deciding, log the open question, stop self-scheduling, and hold for the user. Clear-cut calls still proceed either way; this changes only true forks.

4. **Build + PR. Merge only if `--merge-allowed`.** Do the work on a feature branch, commit, push, open the PR, and run `gh pr checks`. **Without `--merge-allowed`:** stop before merging to main; leave merge for the user's review on return. **With `--merge-allowed`:** once `gh pr checks --required` is green, merge with `--merge --delete-branch` and confirm via `git fetch && git log --oneline -3 origin/main`; a red or pending check still parks the merge. Either way: never push directly to `main` (PR-only; the hook enforces it), and never `gh pr merge --auto` (it silently no-ops on this repo).

5. **Honor every standing rule of the project you are in.** Its `CLAUDE.md`, `.claude/rules/*.md`, `.claude/project.md` and memory rules all still apply, and anything they mark LOCKED stays locked ("explicit permission" cannot be self-granted while AFK; no flag in this skill unlocks it). In Sembr that includes the dashboard hero (`dashboard-hero.js` and its CSS). Mobile-first + progressive enhancement + DRY. No PII in commits/logs/prompts. Only stage files inside the current project's own directory.

6. **Outward / irreversible actions stay parked unless a flag covers them.** Beyond opening a PR, anything external (emailing, deploying, posting to GitHub beyond the working PR, opening issues, closing issues) parks by default; an action is permitted only if it is explicitly named in `--allow-outward <list>`. Genuinely destructive actions (deleting data, dropping tables, force-push, history rewrite, bulk deletes) park ALWAYS. The destructive floor has exactly one opening, `--allow-test-db-reset`, covering `sembr:reset-test-db` against the local test databases and nothing else; see the floor section for why. Log anything parked rather than doing it.

7. **Self-pace across turns.** When you reach a natural stopping point but the work or rubric-priority backlog is not exhausted, schedule yourself to continue with `ScheduleWakeup` so you keep making progress until the user returns. Pick the next item by rubric priority. Use a long idle delay (1200s+) unless you are actively polling something external (e.g. `gh pr checks` on a running CI run, where ~270s is right). Keep the same `/afk` prompt so the loop re-enters this skill. Stop scheduling once there is genuinely nothing left to safely do autonomously — then wait. **A `ScheduleWakeup` cannot be cancelled and outlives the AFK session: it WILL fire even after the user returns, and the stray-wakeup guard at the top is what absorbs that. So arm as FEW as possible — prefer the harness's automatic completion-notification for harness-tracked background work (it re-invokes you with no orphan timer), and reach for `ScheduleWakeup` only when genuinely idle with backlog and nothing else will wake you. Never arm a short poll for work that already notifies. Every wakeup you set is a future stray firing the guard has to catch.**

## The AFK log

Maintain a running log in the CURRENT project: the path in `.claude/project.md`'s `afk_log` key, or `.claude/afk-log.md` under the project root when there is no such key. Never another project's log: two AFK runs on two projects at once must not write into one file. Keep it out of commits (gitignored or unstaged) — do NOT commit it as part of feature work. Each entry:

```
## <ISO timestamp> — <short title>
- Decision: <what you chose>
- Rubric basis: <which rubric tier resolved it, or "toss-up, picked reversible">
- Parked (needs the user): <anything you deferred>
```

This is what the user reads on return to see what happened and what still needs them. Convert relative times to absolute (the date is in the environment context).

## Resume ("I'm back")

When the user signals return:

1. Exit autonomous mode. Stop self-scheduling. All flag grants expire — they applied only to that AFK session. **You cannot cancel a `ScheduleWakeup` that is already pending (no cancel tool exists), so one armed during this AFK may still fire once after you resume. That is expected and handled: the stray-wakeup guard at the top of this skill makes that firing a no-op. Do NOT arm any new wakeup on resume.**
2. Give a tight summary: what you completed, what merged (if `--merge-allowed` was set, with the confirming `git log` line), what's in PR awaiting their review (with PR numbers + `gh pr checks` status), the full list of **parked** items, and any **logged toss-up decisions** (or, under `--stop-with-questions`, the questions you stopped on) they should sanity-check.
3. Re-raise any questions you deferred. Now you may use `AskUserQuestion` again.
4. Wait for direction. Never `gh pr merge --auto`.
