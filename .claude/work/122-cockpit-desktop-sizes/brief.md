# 122. Cockpit at desktop sizes: the monitor is beside Rob's face (Small)

## Objective
The 1080x1920 portrait cockpit uses normal desktop type and control sizes.
The hero shrinks to about half its height, the space goes to the four list
cards, the Agenda stops leaving dead space, the Follow-ups add box shows in
full, and kc-finance lines stop repeating their kind.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 122
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\121-cockpit-hero\result.md`

## Context
Rob, 2026-11-01, on card 121's result: "the text just keeps getting bigger and
bigger... The monitor is beside my face, Cas." Cards 119 and 121 sized the
page for reading across a room. That was wrong. Keep 121's layout (the hero
grid, the limits band, the Cas bar beside Google search, Find in the rail)
and change only sizes, plus the three fixes below. Target values inside the
portrait block (`@media(min-width:1000px) and (max-aspect-ratio:4/5)`):
- Page top padding 20px. Hero row-gap 14px, column-gap 32px.
- Clock 72px light, AM/PM 18px. Date and greeting 17px, on two lines as now.
- Weather: temperature 52px, condition 17px, town 14px, high/low line 14px,
  hourly cells 16px with 12px labels and 8px padding above.
- Limits band: 12px padding top and bottom, label 13px (age 12px), value 20px,
  "No reading yet" 14px, bar 4px with 8px above.
- Cas bar and Google search 44px tall, input text 15px, icons 18px, Cas dot
  26px, mic 32px, the voice button 28px tall at 13px.
- Grid cards: text 14px, card titles 16px, padding 14px 16px, grid gap 12px.
- Agenda: day cells 24px tall at 13px, the card only as tall as its content.
  No card other than the four list cards (Needs you, Agent talk, Email,
  Working now) has more than 24px of empty space under its last line. Re-pair
  the grid areas below the list cards if that is what it takes.
- The height the hero gives back goes to the four list cards.
- Follow-ups: the "Add a follow-up" input is fully visible, not clipped.
- kc-finance lines (`views.js` around line 231): drop the kind column (it
  repeats the subject, "payment review / Payment to review"), and show no date
  cell when the date is null instead of a dash.

## Start here
- `public/cockpit/cockpit.css`: the portrait block starts at the line
  `/* Card 119: monitor 2, 1080 x 1920 portrait.` and card 121's hero rules
  follow it (`.hero-clock`, `.wx-temp`, `.fit-hero .limit-val`,
  `.fit-hero .google-form`, `.fit-hero .command-main`, `.fit-grid ...`).
- `public/cockpit/js/views.js:230-231` builds the finance lines:
  `f.lines.forEach((x) => add(lines, add(el('div', 'finance-line'), el('span', '', x.kind), el('b', '', x.subject), el('small', '', x.date ?? '–'))));`
- Tests that pin the hero or finance markup: `tests/CockpitHeroTest.php`,
  `tests/CockpitFitTest.php`, `tests/CockpitPortraitTest.php`.
- Done-test: `c:\xampp\php\php.exe tests/run.php --filter Cockpit`

## Files you may touch
- `public/cockpit/cockpit.css`
- `public/cockpit/js/views.js`
- `tests/CockpitHeroTest.php`
- `tests/CockpitFitTest.php`
- `tests/CockpitPortraitTest.php`
- `.claude/work/122-cockpit-desktop-sizes/progress.md`, `result.md`, and screenshots in that folder

## Done-test
From plan.md card 122, word for word:

`c:\xampp\php\php.exe tests/run.php --filter Cockpit` passes, including new
CockpitHero tests proving: inside the 1080x1920 portrait block no font-size
is above 72px, the clock is 72px, grid card text is 14px, and the Cas bar and
Google search are 44px tall; a finance line has no kind cell and no dash when
its date is null.

Then the mutation check through the runner
(`node C:\Users\roban\.claude\bin\project-test.cjs`): set the clock back to
148px, run filtered to Cockpit, name the tests that failed, restore, run green.
Then one headless screenshot of the live page at 1080x1920 (Edge headless,
`--window-size=1080,1920 --timeout=8000`, its own `--user-data-dir`): nothing
overlapping, clipped or wrapped, no dead block. Save the path in result.md.

## Boundaries
No commit, no push, no install, nothing outward. Sizes and the three named
fixes only; no change to collectors, the API, list order or the layout 121
built beyond re-pairing grid areas. `project.md`'s Never list applies.

## Progress
Keep `progress.md` beside this file from your first step, one line per step
that changes a file or finishes a unit of work: done, files, next, anything
half-written. Already there? You are resuming: read it and check the disk
against it first.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, the screenshot path,
and any call made and why. Say what was not done.
