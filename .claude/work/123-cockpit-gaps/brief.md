# 123. Cockpit gaps: Agenda dead space, finance clip, Care half-empty (Small)

## Objective
Three leftovers from card 122 on the 1080x1920 portrait cockpit. The Agenda
card stops leaving empty space under its last line, the kc-finance card stops
cutting its last line at the card edge, and Care clients fills the full width
instead of leaving the right half empty.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 123
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\122-cockpit-desktop-sizes\result.md`
  and `shot-1080x1920-b.png` in that folder (the state this card fixes)
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\120-autostart-vu\result.md` (120 touched
  the same CSS and views just before this card)

## Context
Measured on 122's capture: the Agenda shares a grid row with AI news, which is
taller, so about 55px sits empty under "Nothing on today." The kc-finance card
is capped at 232px and its last line ("Todo") is cut in half at the bottom
edge. Care clients spans the full width but lists two clients in one column,
so the right half is empty. The monitor sits beside Rob's face: keep 122's
desktop sizes, do not grow any type.

Wanted, inside the portrait block (`@media(min-width:1000px) and (max-aspect-ratio:4/5)`):
- Agenda: the row is as tall as the Agenda's content; AI news takes that same
  height and scrolls inside. No more than 24px empty under the Agenda's last line.
- kc-finance: no line cut at the card edge. Either the card fits all its lines,
  or it scrolls and the visible area ends on a whole line. Follow-ups beside it
  keeps its add box fully visible.
- Care clients: clients laid out in two columns across the full width, so a
  short list fills both halves; three or more wrap row by row, newest first order kept.
- Follow-ups (post-120 capture, `.claude/work/120-autostart-vu/live-1080x1920.png`)
  has about 80px empty under its add box with one follow-up; size it the same
  way as the Agenda pair: the row follows the shorter card's content and the
  taller one scrolls inside, so no card outside the four list cards has more
  than 24px empty under its last line.
- The page still has no page scroll at 1080x1920; any height freed goes to the
  four list cards (Needs you, Agent talk, Email, Working now).

## Start here
- `public/cockpit/cockpit.css`: the portrait block (`/* Card 119: monitor 2, 1080 x 1920 portrait.`)
  and 122's grid areas `"agenda news" / "follow finance" / "care care"`, the News
  306px and Finance 232px caps.
- `public/cockpit/js/views.js`: the finance lines and the Care list markup.
- Tests: `tests/CockpitHeroTest.php`, `tests/CockpitFitTest.php`, `tests/CockpitPortraitTest.php`.

## Files you may touch
- `public/cockpit/cockpit.css`
- `public/cockpit/js/views.js` (Care and finance markup only)
- `tests/CockpitHeroTest.php`, `tests/CockpitFitTest.php`, `tests/CockpitPortraitTest.php`
- `.claude/work/123-cockpit-gaps/progress.md`, `result.md`, and captures in that folder

## Done-test
From plan.md card 123, word for word:

`c:\xampp\php\php.exe tests/run.php --filter Cockpit` passes, including new
CockpitFit tests proving: inside the portrait block the AI news card's height
follows the Agenda's row (it scrolls, and has no fixed px height of its own),
the kc-finance card has no fixed max-height that is not a whole number of
lines, and the Care client list is a two-column grid.

Then the mutation check through the runner
(`node C:\Users\roban\.claude\bin\project-test.cjs`): put back News' 306px
height, run filtered to Cockpit, name the tests that failed, restore, run green.
Then one headless capture of the live page at 1080x1920 (headless Playwright
or Edge headless with its own `--user-data-dir`; never the real Chrome, Henike
holds it), saved in the card folder. In result.md give the measured empty
space under the Agenda's last line, whether the finance card's last visible
line is whole, and whether both Care columns hold a client.

## Boundaries
No commit, no push, no install, nothing outward. Layout of these three cards
only; no type size changes, no change to collectors, the API or list order.
`project.md`'s Never list applies.

## Progress
Keep `progress.md` beside this file from your first step, one line per step
that changes a file or finishes a unit of work: done, files, next, anything
half-written. Already there? You are resuming: read it and check the disk
against it first.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests
added, the mutation result naming the tests that failed, the capture path and
the three measurements. Say what was not done.
