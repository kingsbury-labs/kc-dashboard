# 119. Cockpit fitted to monitor 2: a hero on top, no dead space (Normal)

## Objective
The cockpit overview fills Rob's second monitor exactly (Chrome full screen,
1080 x 1920 portrait) with no page scroll and no dead space: a large-type hero
across the top, then a fixed grid where every card owns a slot and long lists
scroll inside their own card.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 119
- `D:\Dev\htdocs\_kc-dashboard\public\cockpit\js\views.js`, `main.js`, `public\cockpit\cockpit.css`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\112-cockpit-portrait\result.md`, `115-cockpit-care\result.md`, `110-cockpit-voice\result.md` (what is on the page now)

## Context
- Measured 2026-11-01: DISPLAY2 is 1080 x 1920, portrait, 100% scaling,
  Chrome full screen. Rob: "It's kind of scattered all over with lots of dead
  space." and "The top area can/should be a hero with bigger text so I can see
  the date, time, search bars, weather, limits, etc." He reads it from his
  chair, not up close.
- Target at 1080 x 1920 (the only layout that matters; leave others working
  but do not tune them):
  - Hero, about 420 px tall, full width: the time very large (about 96 px,
    light weight) with the date under it (about 28 px); Google search and the
    Cas bar as large inputs (about 64 px tall, 22 px text); weather (big
    temperature, condition, high/low, next hours) and plan limits (Claude and
    Codex, 5-hour and weekly bars with percents at about 20 px) as two blocks
    on the hero's right or as a row under the time. The greeting line with
    "N things need you" stays, at about 24 px. No card borders inside the hero;
    it reads as one surface.
  - Below the hero, a CSS grid filling the remaining height exactly (100vh
    minus the hero), two columns, fixed row tracks: Needs you, Agent talk,
    Email, Working now get fixed heights and scroll inside (thin scrollbar);
    Agenda puts the month grid and today's list side by side; kc-finance,
    Follow-ups, AI news and the Care summary size to content inside their slot.
    No card stretches past its slot; no gaps wider than the 16 px grid gap.
  - The care banner and voice states overlay; they never push the layout.
- Body text in cards at least 15 px, headings 18 px; the hero is where size goes.

## Files you may touch
- `public/cockpit/js/`, `public/cockpit/cockpit.css`
- `tests/CockpitFitTest.php` (new), `tests/CockpitPortraitTest.php`
- `.claude/work/119-cockpit-fit/progress.md`, `result.md`

## Done-test
From plan.md card 119, word for word:

`c:\xampp\php\php.exe tests/run.php --filter Cockpit` passes, including new
CockpitFit tests proving: at the 1080x1920 portrait query the overview root is
`height: 100vh` with `overflow: hidden`; the hero is the first child; every
overview card is placed in a named grid area; the list cards (Needs you, Agent
talk, Email, Working now) have `overflow-y: auto`.

Then take headless screenshots at 1080x1920 of the live page
(`http://127.0.0.1:8787/cockpit.php`, Edge headless with `--timeout=8000`, one
profile per shot) and look at them; fix anything clipped, overlapping or
empty before you stop. Save the shot path in result.md.

## Boundaries
No commit, no push. Never list applies.

## Progress
Keep `progress.md` beside this file, one line per step. Resume from it if present.

## Report
`result.md` under 200 words: files, tests, the screenshot path, what you
changed in the hero. Say what was not done.
