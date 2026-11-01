# 119 result

Files: `public/cockpit/js/views.js` (new `renderFit`, `searchForm`, hero clock), `public/cockpit/cockpit.css` (card 119 block at the end; the old portrait blocks, cards 112 and the earlier ones, removed), `tests/CockpitFitTest.php` (new, 4 tests), `tests/CockpitPortraitTest.php` (last test rewritten for the new layout).

Tests: `--filter Cockpit` 58 passed.

Mutation: removed `overflow:hidden` from `.overview-page.fit`; CockpitFitTest "at the portrait query the overview root is 100vh and hides overflow" failed. Restored, green.

Hero (420 px): greeting 24 px, time 96 px light, date 28 px, Google search and Cas bar 64 px with 22 px text, weather (64 px temperature, hours) over plan limits (20 px, resets line hidden) on the right, no card borders. Topbar Find is fixed top right; care banner and voice thread/status are overlays.

Grid: two columns, named areas, rows 1.1fr/1fr/auto/auto/auto; Needs, Agent talk, Email, Working now scroll inside; Agenda has the month beside today's list; finance, news, follow-ups, care capped at 250 px.

Screenshot (1080x1920, live data, via DevTools capture because plain `--screenshot` fires before the snapshot loads): `C:\Users\roban\AppData\Local\Temp\claude\D--Dev-htdocs--kc-dashboard\536222a9-2dd0-483b-ba9b-a06c6429f691\scratchpad\fit9.png`. No page scroll, bottom card ends at 1904 of 1920.

Not done: other layouts not tuned; the "Voice is off" chip overlaps the search bar's lower right edge.
