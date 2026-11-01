# 121. Cockpit hero rebuilt to the approved concept, loose layout fixed (Normal)

## Objective
The cockpit overview at 1080 x 1920 has the hero Rob approved on 2026-11-01
(`concept.html` beside this brief), Find lives in the left rail, the "Voice is
off" chip is gone from the search edge, kc-finance hides figures with no data,
Agenda no longer spans an empty half, and nothing on the page overlaps or
scrolls the page.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 121
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\121-cockpit-hero\concept.html` (the spec for the hero, read all of it)
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\119-cockpit-fit\result.md` (what 119 built)

## Context
- The spec is `concept.html`, approved by Rob on 2026-11-01 ("Good, go ahead").
  Match its layout, sizes and copy; use the live data the current hero uses. A
  screenshot of it at 1080x1920 is
  `C:\Users\roban\AppData\Local\Temp\claude-chrome-screenshots-nYgHWI\screenshot-1793567904323-2.png`.
  In words: one grid, 3fr / 2fr. Left: time at 148 px light Manrope with a
  small "PM" beside it, then the date (30 px, #dfe3e8) and "Good afternoon Rob"
  (30 px, muted) under it. The "N things need you" text is dropped from the
  greeting. Right: temperature 100 px, condition 28 px, town 20 px muted, then
  "High 17° · overnight low −5°" (the low is the day's `temperature_2m_min`,
  which is often the early morning, hence the word), then five hourly cells in
  a fixed 5-column grid that cannot wrap. Next, full width between two hairlines:
  plan limits as four equal tiles (Claude 5-hour, Claude week, Codex 5-hour,
  Codex week), label 18 px #c3c9d1 with the vendor bold, value 36 px, a 6 px
  bar. An unknown reading shows "No reading yet" over a dashed line, no bar,
  no "--". A reading older than one hour shows its age small beside the label
  ("2h ago"); fresh readings show none. Last row: the Cas bar (1.5fr) and
  Google search (1fr) side by side, 68 px tall. The voice mode toggle sits
  inside the Cas bar as its button ("Voice off" and the other modes'
  labels); the separate "Voice is off" chip that overlapped the search border
  is removed, and its message moves into that button's text or title. The hero
  bottom lands near y=495 in the concept; stay within 40 px of that.
- Find: the floating Find box at top right goes. A search icon button in the
  left rail, under the KC mark, opens the same fuzzy finder (main.js around
  line 85); Ctrl K still opens it.
- Below the hero, keep 119's fixed grid and its no-page-scroll rule. Agenda
  takes one column (month grid over today's list) instead of the full width;
  re-pair the cards so no slot is empty and no row is half blank. kc-finance
  shows only figures that have data (today all four are dashes, so the strip
  disappears and its alert lines move up); alert rows keep their dates.
- A stray round X at the top centre in one of Rob's shots was not reproducible
  at 1080x1920; it was most likely the Chrome extension overlay, not the page.
  Do not chase it beyond checking the page has no such control.
- Every list stays newest first (card 120 enforces that everywhere; do not
  change list order here).

## Start here
- `public/cockpit/js/views.js:385-418` builds the hero (`fit-hero`,
  `hero-main`, `hero-side`) and the grid: `add(grid, needsCard(),
  agentTalkCard(), emailCard(), agentsCard(), calendarCard(), financeCard(),
  newsCard(), followCard(), careCard())`.
- `public/cockpit/js/views.js:215-230` is `financeCard()`; the four rows are
  built at line 222.
- `public/cockpit/js/views.js:241-260` is the Agenda card.
- `public/cockpit/cockpit.css:70-112` holds card 119's portrait rules
  (`.fit-hero` from line 78).
- `public/cockpit/js/voice.js` renders `.voice-line` and the mode toggle.
- `public/cockpit/js/main.js:85-145` builds the Find search box.
- Tests that pin today's hero and grid: `tests/CockpitFitTest.php`,
  `tests/CockpitPortraitTest.php`, `tests/CockpitFixesTest.php` (top bar),
  `tests/CockpitVoiceTest.php` ("Voice engine is off" text).
- Done-test: `c:\xampp\php\php.exe tests/run.php --filter Cockpit`

## Files you may touch
- `public/cockpit/js/views.js`
- `public/cockpit/js/main.js`
- `public/cockpit/js/voice.js`
- `public/cockpit/cockpit.css`
- `tests/CockpitHeroTest.php` (new)
- `tests/CockpitFitTest.php`
- `tests/CockpitPortraitTest.php`
- `tests/CockpitFixesTest.php`
- `tests/CockpitVoiceTest.php`
- `.claude/work/121-cockpit-hero/progress.md`, `result.md`, and screenshots in that folder

## Done-test
From plan.md card 121, word for word:

`c:\xampp\php\php.exe tests/run.php --filter Cockpit` passes, including new
CockpitHero tests proving: the hero has the clock, weather, a limits band of
exactly four tiles, and a row holding the Cas bar and Google search; the
greeting has no "need you" text; an unknown limit renders "No reading yet" and
no bar fill; the page has no `.voice-line` chip outside the Cas bar; the Find
input is no longer in the top bar and a rail button opens the finder; the
finance strip renders no row whose value is missing; the Agenda card is not
full width in the 1080x1920 query.

Then the mutation check through the runner
(`node C:\Users\roban\.claude\bin\project-test.cjs`): break the new code on
purpose, run filtered to Cockpit, name the tests that failed, restore, run
green. Then headless screenshots of the live page at 1080x1920
(`http://127.0.0.1:8787/cockpit.php`, Edge headless with `--timeout=8000`, one
profile per shot), compared side by side with the concept screenshot; fix
anything clipped, overlapping, wrapped or empty before you stop. Save the shot
paths in result.md.

## Boundaries
No commit, no push, no install, nothing outward. No change to collectors, the
API or list order. `project.md`'s Never list applies.

## Progress
Keep `progress.md` beside this file from your first step, one line per step
that changes a file or finishes a unit of work: done, files, next, anything
half-written. Already there? You are resuming: read it and check the disk
against it first.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, the screenshot paths,
and any call made and why. Say what was not done.
