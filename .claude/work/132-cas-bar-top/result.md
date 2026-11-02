Card 132 result (round 2)
Files: views.js (dockAsks; renderOverview now docks the bar and search in a top row at 700-1099px), cockpit.css (Card 132 block), main.js, CockpitHeroTest.php (2 tests).
Round 2 fix: at 900x1600 the bar was squeezed into a 261x279 column beside the weather with the search split off. Now both sit in one top row, 44px tall.
Tests: --filter Cockpit, 96 pass.
Mutation (round 1): .command position:fixed in override failed "card 132: at 700px and up no .command rule is position:fixed..."; restored, green.
Captures (Playwright, own profile, http://127.0.0.1:8787/cockpit.php), all in this folder:
p1080-overview, p1080-project, p1080-session, p1080-agents, p1080-care (.png at 1080x1920), p900-overview.png (900x1600).
Seen: one .command each time, 44px, side by side with the 44px Google search, position relative; under the page head on sub pages (y=143), in the hero on the overview (y=256); nothing overlaps; nothing pinned at the bottom.
Not done: phone width (<700px) untouched; no new test for the 700-1099 overview dock.
