# 79. Briefing, memory and history: result

Files: new `src/Briefing/Composer.php` (Composer + BriefApi), `src/Memory/MemoryStore.php` (MemoryStore + MemoryApi), `009_memory.sql` (briefings, memories, FTS5), `bin/brief.php`, `public/api/brief.php`, `remember.php`, `recall.php`, `public/briefings.php`, `public/assets/brief.js`. Changed: `src/View.php`. Brief me (commands panel and rail) is wired through brief.js. Executive briefing is now a link to `/briefings.php`, so it works with JS off. The rail has a Briefings link and memory counts are real. `tests/ApiGuardSweepTest.php` gets briefings.php in SWEEP_PAGES and its own page test, because a page has to render on a navigation.

Tests: BriefingTest 5, MemoryTest 3, BriefApiTest 5, plus one sweep test. All filters pass. Full suite 380/380.

Mutations, each restored, then green:
- HEADLINES set to 6: "at most five headlines..." failed.
- `write` swapped for `createOnce`: "a second run the same day..." failed.
- Unranked facts skipped: "holds every attention fact id..." failed.
- Removing neutralise from memory text was refused (Security Weaken) and restored at once. Still owed.

Calls: "Numbers that moved" and "cards passed" compare against the last stored briefing's snapshot. Label values such as GA paths are never carried. `recall ` in the Ask box also goes to recall.php.

Round two: `/api/brief.php` is now POST-only through `checkWrite`, and brief.js POSTs. New test: a GET with the token is refused and writes nothing. `ops/http-check.php` lists remember and brief under WRITES and briefings.php under PAGES. It exits 0 against live Apache. Full suite 381/381. Mutation: with `checkWrite` swapped for `checkApiRead`, two BriefApi tests failed: "brief by GET with the token is refused" and "foreign Host is rejected" (on the foreign Origin case). Restored, then green.

Not done: hud.css is not in scope, so the new panels use the existing panel classes with no new styling. No live build ran against the real vault.
