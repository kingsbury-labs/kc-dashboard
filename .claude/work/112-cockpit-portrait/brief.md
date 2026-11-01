# 112. Cockpit tuned for portrait, finance alerts readable (Risky)

## Objective
The cockpit is laid out for the one place it runs: Chrome, full screen, on
Rob's second monitor in portrait (1080x1920 or 1440x2560). Both columns end
near the same height, long feeds are capped, and the kc-finance panel shows
kc-finance's real alerts instead of dashes.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 112
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\111-cockpit-fixes\result.md`, `109-cockpit-day\result.md`
- `D:\Dev\htdocs\_kc-dashboard\public\cockpit\js\views.js`, `main.js`, `public\cockpit\cockpit.css`
- `D:\Dev\htdocs\_kc-dashboard\src\Collectors\Finance.php`, `src\Cockpit\CockpitDaily.php`

## Context
- Rob, 2026-11-01: "the dashboard will always be running in portrait (long) on
  monitor 2, so you can optimize the layout for that." and "this will only ever
  run in chrome on my desktop pc." Landscape and phone layouts can stay as they
  are; do not spend effort on them.
- Seen live at 1440x2560 (screenshot checked 2026-11-01): the right column
  (Agent talk) runs 30+ items past the bottom while the left column ends at
  about 60% of the height. Cap Agent talk at 10 items with a "Show 24 more"
  text button, and Working now at 8; then balance the two columns so neither
  ends more than about 15% of the screen height before the other (move a card
  across if needed). Email summaries: show 6, then "Show all".
- Portrait top section: search, greeting, Cas bar and plan limits left;
  weather right. Weather's card is short, so let the space under it hold the
  agenda's mini calendar, or let plan limits sit under weather; pick the one
  with less empty space and say which in result.md.
- kc-finance's alerts.json today has 8 facts, every one with `subject: null`
  (id, kind, severity, due_date, amount_cents, url, evidence present). Both
  readers require a subject: `Finance.php` throws ("finance fact 0 needs id,
  kind and subject", in data/app.log on every collector run) and the cockpit's
  reader skips them all, so the panel is all dashes. Treat a null or empty
  subject as absent: label the alert from its kind in plain words
  (payment-review becomes "Payment to review") plus amount and due date when
  present. Do not change what is required otherwise.

## Start here
`node C:\Users\roban\.claude\bin\project-test.cjs -- c:\xampp\php\php.exe tests/run.php --filter Cockpit`
then the same with `--filter Finance`.

## Files you may touch
- `public/cockpit/js/`, `public/cockpit/cockpit.css`
- `src/Cockpit/CockpitDaily.php`, `src/Collectors/Finance.php`
- `tests/CockpitPortraitTest.php` (new), `tests/CollectorsTest.php`, `tests/FinanceCollectorTest.php`
- `tests/CockpitFixesTest.php`: only the card 111 test "a finance file with facts missing fields..." whose null-subject expectation this card reverses (expect 3 lines, the payment review label first)
- `tests/fixtures/cockpit-daily/`, `tests/fixtures/` finance fixtures if present
- `.claude/work/112-cockpit-portrait/progress.md`, `.claude/work/112-cockpit-portrait/result.md`

## Done-test
From plan.md card 112, word for word:

`c:\xampp\php\php.exe tests/run.php --filter Cockpit` and `--filter Finance`
pass, including new tests proving: a finance fact with a null subject is
collected with a label made from its kind (both readers), and the panel shows
it; Agent talk renders at most 10 items until expanded and Working now at most
8; the portrait media query places every overview card in one of two columns.

Then the mutation check (make the null subject throw again, then drop the
Agent talk cap), name the failing tests, restore, green. Filtered runs only.

## Boundaries
No commit, no push, nothing outward. Never list applies.

## Progress
Keep `progress.md` beside this file, one line per step. Already there? Resume
from it after checking the disk.

## Report
`result.md` under 200 words: files, tests, mutation result, which portrait
layout choice you made. Say what was not done.
