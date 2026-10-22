---
name: brief
description: Write one task brief for a worker agent, in the shared shape, as a file under the project's .claude/work/. Use before starting any implementer, sweeper or planner agent, from the coordinator loop or by hand. Triggers on "brief", "write a brief", "hand this to an agent", "next card".
---

# Brief

One task, one folder, one brief. The worker reads the brief and the files it
names, and nothing else. The coordinator reads the worker's `result.md` and
never its transcript. This is ICM's stage contract: plain text is the
interface, and loading less beats summarising more.

## Before writing

1. Read `.claude/project.md` in the project. No file means the project has
   not joined the system yet: stop and say so, do not guess its test command.
2. Find the task's done-test: in the file `project.md`'s `done_tests` key
   names, or in a `planner`'s `plan.md` under `.claude/work/`. A task
   without a done-test written down in either does not get a brief. Write
   the done-test first, or send it back to planning.
3. Pick the role (agent name) and model from the table below.

| Role | Agent | For |
|---|---|---|
| Write code | `implementer` | anything that changes behaviour |
| Mechanical sweep | `sweeper` | renames, docblocks, tables, formatting |
| Plan a feature | `planner` | turning a feature into cards with done-tests |
| Security pass | `security-auditor` | anything on `project.md`'s risky paths |

Any file on a risky path in `project.md` moves the implementer up to the
top tier (pass `model` when starting it) and adds a `security-auditor` pass
after it.

## Where it goes

`.claude/work/NN-short-name/brief.md`, NN the next free number in that
folder, two digits. The worker writes `result.md` beside it.

## The shape

Every section, every time, in this order. Paths absolute.

```markdown
# NN. Title

## Objective
What exists when this is done, in two or three sentences. The outcome,
not the steps.

## Read first
The project's `CLAUDE.md` and `.claude/project.md`, then only the files
this task needs: the ADR sections, the code it changes, one example of
the style to match. Paths, not pasted contents.

## Start here
Filled from what you already read, so the worker starts editing instead of
exploring (it spends a quarter of its time and over a third of its turns
before the first edit). Keep it to about 30 lines:
- Files to open, with line ranges: `/abs/path.php:120-180`.
- The few snippets the edit hangs on: a signature, the line to change.
  Never whole files.
- The seam or existing function to reuse, by name and path.
- The exact filtered command for the done-test, ready to paste.

Have not read the code? Say so in one line. Do not guess a range.

## Files you may touch
An explicit list, one backticked path per bullet. New files named. The
coordinator checks this list with `--check-scope`, so a path not here is
a failed card. Grep the tests for the files, routes, strings and section
names the card changes, and list every test and fixture that will have
to follow: a test the worker cannot update sends the card back for a
second worker.

## Done-test
Word for word from where the project keeps it. Then the mutation check,
through the runner (`node D:\Dev\htdocs\_kc-dashboard\.claude\bin\project-test.cjs`) so
it is on record: break the new code on purpose, run, name the tests that
failed, restore, run again green. Every run is filtered to the done-test
and the tests this card adds or changes. Never ask for the full suite:
the gate runs it once, and workers re-running it cost Henike's cards up
to twenty minutes each (2026-10-20).

## Boundaries
No commit, no push, no install, nothing outward. Plus this task's own
limits, and `project.md`'s Never list by reference.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, and any call made
and why. Say what was not done.
```

Add a `## Context` section between Read first and Files only when the worker
needs a fact it cannot find by reading: real output to match, a decision
made in conversation, a trap already hit. Keep it to what changes the work.

## Checks before handing it over

- A stranger could do the task from the brief alone, without this
  conversation.
- The done-test is something a command can confirm, not "works well", and
  Start here holds that command.
- The files list is short enough that touching anything else is plainly
  wrong.
- Nothing in it is a secret or a value read from one.

Then record the task in `.claude/work/checklist.json` (`{"id", "title",
"brief", "start_sha": <git rev-parse HEAD>, "passes": false}`) and start
the agent in the background with the brief's path as the whole prompt.
`passes` only ever changes through the runner's `--pass`.
