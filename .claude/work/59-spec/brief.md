# 59. Spec update for revision 4

## Objective
`docs/SPEC.md` describes the dashboard as plan revision 5 builds it: mail and
gpt-oss gone, the attention engine and the kc-finance integration in, Ask
Claude on `claude -p --model sonnet`, every path under `D:\Dev\htdocs`.
`tests/SpecTest.php` guards those facts and still guards every Security fact
it guarded before.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, the header, card 59, and skim every card heading and the Later section (the spec's Features list should match the cards)
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\attention-engine.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\finance-contract.md`
- `D:\Dev\htdocs\_kc-dashboard\docs\SPEC.md`
- `D:\Dev\htdocs\_kc-dashboard\tests\SpecTest.php`

## Context
- Coordinator's call on the existing SpecTest needles: `UIDVALIDITY` is a
  mail-digest detail outside the Security section; drop that needle with mail.
  `never stored` stays (the finance never-store list can carry it).
  `--cache-type-k q8_0` and `enable_thinking` stay: the local model moves to a
  Later section of the spec, it is not deleted. `127.0.0.1:8787`, `8.2.18`,
  `realpath`, `collect_ga.py`, `S4U` stay.
- Keep the Security section's existing bullets. A Security bullet that names
  mail (IMAP read-only) may be removed only if mail is the whole of it; say so
  in result.md.
- Revision 5's JARVIS cards (73 to 80) belong in Features too, briefly.

## Files you may touch
- `D:\Dev\htdocs\_kc-dashboard\docs\SPEC.md`
- `D:\Dev\htdocs\_kc-dashboard\tests\SpecTest.php`

## Done-test
From plan.md card 59, word for word:

`php tests/run.php --filter Spec` passes. SpecTest asserts SPEC.md has no
`c:\xampp\htdocs` (case-insensitive), no `gpt-oss`, no `IMAP`; has headings
or lines naming `facts`, `alerts.json`, `also seen`, `coverage`; and still
has every Security bullet SpecTest checked before.

Then the full suite passes through the runner
(`node D:/Dev/htdocs/_kc-dashboard/.claude/bin/project-test.cjs`, forward
slashes in bash), and the mutation check through the runner: put
`gpt-oss` back into SPEC.md, run `--filter Spec`, name the failing test,
restore, run green.

## Boundaries
No commit, no push, no install, nothing outward. `project.md`'s Never list
applies. Do not touch any file outside the list.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, and any call made
and why. Say what was not done.
