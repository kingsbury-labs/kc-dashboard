# 112 result

**Green.** `tests/CockpitFixesTest.php` (added to the list on resume) now expects the null-subject fact labelled: `alerts` 3, `payment review / Payment to review` line first. `--filter Cockpit` 33 pass, `--filter Finance` 15 pass.

Files: `src/Collectors/Finance.php` (new `Finance::label`; a null or empty subject is labelled from the kind plus amount and due date, e.g. "Payment to review · $125.50 · 2026-11-05"), `src/Cockpit/CockpitDaily.php` (same rule), `public/cockpit/js/views.js` (Agent talk 10 plus "Show N more", Working now 8, Email 6 plus "Show all", finance moved to the right portrait column), `public/cockpit/cockpit.css` (portrait top rule).

Tests added: `tests/CockpitPortraitTest.php`, 4 tests.

Mutation: made the null subject throw/skip again and set the talk cap to 24. Failed: "a finance fact with a null subject is collected...", "the cockpit finance reader labels a null subject...", "Agent talk renders at most 10...". Restored; all pass except the conflict above.

Layout: plan limits sit under the weather (right column). The calendar stays in the left column, since moving it would leave the left column short.

Not done: no live screenshot to check the column balance.
