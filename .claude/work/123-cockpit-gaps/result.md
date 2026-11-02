# 123 result (round 3)

Files changed: public/cockpit/cockpit.css, tests/CockpitFitTest.php. views.js untouched.

CSS (portrait block): News `contain:size` (row follows Agenda, News scrolls). Finance is capped at its 47px head plus 5 whole lines (`--fin-line:24px`, rows fixed at one line, gaps 0, no bottom padding) and scrolls, so the row follows Follow-ups and the visible area ends on a line edge. Agenda: bottom padding 6px, and the empty-state margin removed. Care list is a 2-column grid.

Test: CockpitFitTest "card 123 ..." now also checks that the Finance cap is `calc(Npx + K * var(--fin-line))` with whole K, that the lines are exactly one line tall, and that the bottom padding is 0. `--filter Cockpit`: 75 passed.

Mutation: the cap was set to 5.5 lines, and "card 123 ..." failed ("finance cap is whole lines"). Restored, green. The round 1 News 306px mutation is on record.

Capture: `.claude/work/123-cockpit-gaps/live-1080x1920.png` (Playwright headless-shell with its own profile, live 127.0.0.1:8787/cockpit.php). Page scroll: 0.

Measured at 1080x1920:
- Agenda: 7px empty.
- Follow-ups: 19px under its add box (was 181).
- Finance: the last visible line (1721-1745) is whole, card bottom 1745. 8 lines, scrolls.
- Care: both columns hold a client.
- Freed height went to the list cards: Needs you/Agent talk 470 to 495, Email/Working now 428 to 450.
