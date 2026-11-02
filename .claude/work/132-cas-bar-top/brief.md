# 132. Cas bar and Google search at the top of every cockpit page, low priority (Small)

## Objective
On every cockpit page, not only the overview, the Cas bar and Google search sit
side by side in a row near the top of the page, the same as in the overview's
hero. Nothing pins the Cas bar to the bottom of the screen any more.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 132
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\122-cockpit-desktop-sizes\result.md` (the sizes to match)

## Context
Rob, 2026-11-01: "the sub pages in the dashboard have the voice and search at
the bottom and it's oddly proportioned. Can we ensure it's always near the top?"
Checked: `main.js:201` appends the `.command` bar to the body. The overview
docks it into its hero (`views.js:461-463` and `513-515` add `.docked`;
`main.js:216-219` takes it back out on navigation). Every other page leaves it
on the old rule `.command{position:fixed;...;bottom:74px}` in `cockpit.css:17`,
`bottom:20px` from 700px, with a width of
`calc((100vw - var(--rail) - 96px) * 2.5 / 3.5 + 8px)` from 1100px (`cockpit.css:19`).
That is a desktop-layout leftover, so on the 1080x1920 portrait monitor the bar
floats at the bottom at an odd width. Google search is built by `views.js:377`
(`.google-form`) for the overview only.

Wanted:
- The project, session, agents and care pages (every route but the overview)
  get a top row under their page head: the Cas bar and Google search side by
  side, 44px tall, at the overview hero's sizes (card 122). Reuse the same
  element: dock the one `.command`, never build a second Cas bar (voice.js
  owns one).
- No `.command` is `position:fixed` on any route at widths of 700px and up.
  Under 700px (a phone), keep whatever is reachable now; mobile first still applies.
- Moving between pages keeps one `.command` in the DOM and voice working.

## Files you may touch
- `public/cockpit/js/main.js`, `public/cockpit/js/views.js` (the docking and the search form only)
- `public/cockpit/cockpit.css`
- `tests/CockpitHeroTest.php`, `tests/CockpitFitTest.php`, `tests/CockpitPortraitTest.php`
- `.claude/work/132-cas-bar-top/progress.md`, `result.md`, captures in that folder

## Done-test
From plan.md card 132, word for word:

`c:\xampp\php\php.exe tests/run.php --filter Cockpit` passes, including new
tests proving: every non-overview route docks the single `.command` into a top
row beside a `.google-form`; no rule at 700px and up leaves `.command`
`position:fixed`; the docked bar and search are 44px tall.

Then the mutation check through the runner
(`node C:\Users\roban\.claude\bin\project-test.cjs`): put `position:fixed` back on
the undocked `.command`, run filtered, name the failing tests, restore, green.
Then headless captures at 1080x1920 of the overview and one project, session,
agents and care page (Playwright with its own browser profile, never the real
Chrome), saved in the card folder: the bar and search are at the top of each page,
nothing overlaps, and nothing sits pinned at the bottom.

## Boundaries
No commit, no push. Layout only; no change to voice.js, the API or list order.
`project.md`'s Never list applies.

## Progress
Keep `progress.md` beside this file from your first step, one line per step
that changes a file or finishes a unit of work: done, files, next, anything
half-written. Already there? You are resuming: read it and check the disk
against it first.

## Report
Write `result.md` beside this file, under 200 words: files, tests, mutation
result, capture paths. Say what was not done.
