---
name: coordinate
description: The coordinator loop for running a project's build with agents, one card at a time. Reads .claude/project.md, briefs a worker per card, gates its work with the project's own tests through the shared runner, commits, and moves to the next. Use when Rob says "run the build", "run agentically", "autopilot", "coordinate", or invokes /coordinate, or alongside /afk when there are cards to work.
---

# Coordinate

The main session is the coordinator and stays thin. It does not write the
task's code. It picks a card, writes a brief, starts one worker, gates the
result with commands, commits, and takes the next card. Every turn re-reads
the whole window, so a thin coordinator is a cheap one.

Everything project-specific comes from `.claude/project.md`. This file names
no project. A project without `project.md`, or without `test_command` in
it, has not joined: say so and stop.

## The runner is the gate

`R` below means `node D:\Dev\htdocs\_kc-dashboard\.claude\bin\project-test.cjs`, run from
the project. Never the raw test command.

- `R` runs the full suite: full log to `test_log`, a record in
  `.claude/work/last-test-run.json` with the real exit code and a
  fingerprint of the working tree, one line in `test-runs.jsonl`, and only
  failures and the summary printed. Run it with `run_in_background` when
  the suite is longer than a minute; the Bash tool's own timeout kills it.
- `R --verify`: exit 0 only for a finished, full, passing run of exactly
  the tree on disk now, inside `verify_max_age_min`.
- `R --pass <id>`: verify, then set that card's `passes: true` in
  `checklist.json`. The only way `passes` becomes true. For a card with a
  brief it also refuses unless the tree is the one `--check-scope` passed.
- `R --check-scope <brief.md> <start-sha>`: fails if the worker changed a
  project file outside its brief's list or made a commit; reports whether
  the journal shows mutation evidence (a failing run, then a passing one);
  on a pass, records the tree it judged on the card's checklist entry.

The tree fingerprint is the project's content as git would commit it,
leaving out `.claude/work/` (so `checklist.json`), the test log, the
`board` file and every `scope_ignore` entry. Moving the board card after
the gate therefore changes nothing, and a commit of exactly the tested
files fingerprints the same as the tested tree.

A record on disk proves nothing by being there. Only `--verify`, run by the
coordinator after its own `R`, counts.

`R` runs one suite at a time per project: a second run exits 75 with
"another test run is using this project (test-run.lock)" before it touches
the test database or the record. Wait and run again; claude-loop does
that by itself. A test run that bypasses `R` is not covered.

## Keys this skill reads from project.md

| Key | Used for | If absent |
|---|---|---|
| `test_command` | everything | not joined; stop |
| `board` | the human board; left out of the fingerprint | `checklist.json` is the board |
| `scope_ignore` | files kept dirty on purpose: never judged, never fingerprinted, never committed | none |
| `test_busy` | a regex for "another run holds the test database"; claude-loop waits it out (up to 30 min) instead of counting a red suite. SparrowBook's would be `Another test run is using the test database` | a busy refusal is a red suite |
| `done_tests` | where done-tests live | the planner's `plan.md` files |
| `ci` | `required` or `none` | `none`: `R --verify` is the whole gate |
| `ci_max` | under claude-loop with `ci: required`, PRs in CI at once; the next card is built while they run | 2 |
| `extra_checks` | commands that must also exit 0 | none |
| `afk_log` | where decisions and parks are logged | `.claude/work/log.md` |

Risky paths, the Never list and anything else are prose in `project.md`;
read them.

## Files

- `.claude/work/NN-name/brief.md` and `result.md`: one folder per task,
  briefs written with the `brief` skill.
- `.claude/work/checklist.json`: `[{"id", "title", "brief", "start_sha",
  "passes"}]`. The machine's view.

## The loop, per card

1. **Pick.** The top card on the board's Now column (or the first
   `passes: false` entry when there is no board) that is not parked. A
   card without a done-test, or a new feature, goes to the `planner`
   first; its `plan.md` cards are then valid done-test sources.
2. **Brief** with the `brief` skill. Add the entry to `checklist.json`
   with `start_sha` = `git rev-parse HEAD` now and `passes: false`. The
   tree must be clean apart from the brief itself.
3. **Start one worker**, in the background, with the brief's path as the
   whole prompt: `implementer` for code, `sweeper` for mechanical work.
   Model: Sonnet by default; the top tier (Fable, or Opus when Fable's
   allowance is out) when the card touches a risky path in `project.md`;
   Haiku for sweeps only. One worker at a time.
4. **Wait** for the completion notification. Do not poll, do not read the
   worker's transcript. Read `result.md`.
5. **Gate**, in this order, stopping at the first failure:
   1. `R --check-scope <brief> <start_sha>` exits 0. `mutation evidence:
      no` sends the card back: the mutation check was not run through `R`.
   2. Read the whole diff, including new files. Tests weakened, deleted
      or skipped to get green send the card back.
   3. Risky path touched: a `security-auditor` pass on the diff, started
      with `model` set to the top tier. A concrete finding goes back to
      the worker.
   4. `R` (full suite), then each `extra_checks` command, all exit 0.
   5. `R --verify` exits 0.
6. **Commit**, in this order. Branch if the card needs one. Move the card
   on the board. `R --pass <id>`. Stage every file the gate tested with
   the board move and `checklist.json`, leaving out only `scope_ignore`
   files, and commit. `git status --porcelain` must then show nothing but
   `scope_ignore` files: anything else means the commit is not the tree
   that was tested. Push in its own call. Open the PR. Merge only when
   `ci: required` checks are green and Rob has said PRs may merge this
   session; with `ci: none`, the gate above was the check. Under
   claude-loop the pass prompt replaces steps 3 to 6: you stop at the gate
   after step 2, and when step 3 applies you name the risky path in the
   gate status's `audit` field instead of starting a `security-auditor`.
   The loop runs that audit as its own pass beside the suite, sends
   findings back like a red suite, and fails the round if the audit gives
   no clean verdict. On green you commit without pushing, the loop checks
   the commit is the tested tree, then it resumes you to push. Outside
   claude-loop, step 3 is yours as written. With `ci: required` you open
   the PR and stop: the loop watches CI, holds the next push while
   `ci_max` PRs are in CI, merges green PRs in the order they were pushed
   (with `--merge-allowed`), and stops on a red one. The next card builds
   on the pushed branch.
7. **Next card.**

A card that fails the gate goes back to the same worker (SendMessage) with
the failing command's output, not to a fresh one.

If it fails a second time, step up one tier before parking: Haiku goes to
Sonnet, Sonnet goes to the top tier. Start a fresh worker on the bigger
model with the same brief, the tree as it stands and both failures'
output, and gate it the same way. One step up per card. A card that
started on the top tier, or fails after the step up, is parked.

## Park and move on

Park the card (the board's Blocked column, or `"parked": "<reason>"` in
`checklist.json`; the reason in the log) when:

- two diagnosed attempts and one step up a tier have not got it through
  the gate (two attempts if it started on the top tier);
- it needs Rob: an account, a key, a decision, or anything outward;
- it would install anything not already approved;
- it is destructive (dropping data, resetting a database with real data,
  rewriting history);
- a guard or the permission check refuses a step. Log what was refused.
  Never route around it, and never ask a worker to do it instead.

A parked card's code goes to its own branch, `wip/<id>-<short name>`, cut
from where the card started; never onto the branch other cards build on.
The working branch may take a commit of only the board, `.claude/work/`
and the log, and is left clean.

A run that dies leaves a `running` record and may leave the test command's
own lock behind; check for a live test process before the second attempt
rather than counting the lock's refusal as a failure. `test_busy` makes
claude-loop do that by itself.

When every remaining card in a feature is parked, start the next feature
whose cards do not depend on the parked ones.

## Windows

One window per feature (Rob, 2026-10-02): hand off (`/handoff`) when the
feature is merged, and resume the next window from the board. Every turn
re-reads the whole window, so a long one costs more per card than a fresh
one. Read `result.md`, the diff and the runner's exit code, never a full
test log or a worker transcript. Stop when only parked cards remain; the
log then holds what needs Rob.

A feature with no card on a risky path goes to one Sonnet worker as one
brief covering all its cards, gated once at the end. A feature with risky
cards keeps a brief per card on the top tier, commits them to one feature
branch, and gets one `security-auditor` pass over the branch diff before
its PR merges; a card touching money still gets its own pass. A project's
`project.md` may tighten this, never loosen it.

Two windows may run at once on different projects, each with one worker
(Rob, 2026-10-02). Each worker counts against the same usage cap, so
never more than two workers across all windows. CI runs on Rob's own
machine and costs no usage; a project's CI sizes its parallel test
workers at run time from the cores and free memory, divided by the CI
jobs running at that moment, so two to four runs share the machine
instead of fighting over it.

## Reporting

When Rob checks in: what landed (PR numbers), what is running, what is
parked and exactly what each parked card needs from him, cheapest first.
A few sentences.
