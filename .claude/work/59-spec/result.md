# 59 result

Files changed: `docs/SPEC.md` (rewritten for revision 5), `tests/SpecTest.php`.

Tests added (SpecTest, 20 total): needles for `claude -p --model sonnet`, `D:\Dev\htdocs`, `facts`, `alerts.json`, `also seen`, `coverage`, `http://127.0.0.1:8000/`, `26h`; absence tests for `c:\xampp\htdocs` (case-insensitive), `gpt-oss` (case-insensitive), `IMAP` (case-sensitive). `UIDVALIDITY` needle dropped per the brief. All other old needles kept.

Mutation: put `gpt-oss:20b` back in SPEC.md. `spec has no gpt-oss` failed (19 passed, 1 failed). Restored, 20 passed. Full suite: 73 passed.

Calls made:
- The Security bullet "Mail bodies and news text are untrusted" named mail but was not only about mail. I kept it, reworded to news text, finance evidence and file content, and added the model-URL rule.
- The project-picker and credentials bullets now say `D:\Dev\htdocs` (the picker also allows configured extra folders).
- Features list follows the cards: JARVIS cards 73 to 80 are items 7 to 13, briefly. The old "terminal button", weekly note, model health strip and loop status are gone from Features (plan puts them in Later). Local model is in a Later section.
- The `never stored` needle is case-sensitive, so the finance line reads "are never stored".

Not done: nothing outstanding.
