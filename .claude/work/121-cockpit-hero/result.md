# 121 result

Files changed: public/cockpit/js/views.js, main.js, voice.js, public/cockpit/cockpit.css, tests/CockpitPortraitTest.php (hero assertion updated).
Tests added: tests/CockpitHeroTest.php (7 tests). `--filter Cockpit`: 65 passed.

Mutation: removed the finance `.filter(([, v]) => v)` and swapped the Agenda grid areas to "agenda agenda" / "news follow". Failed: CockpitHeroTest "the finance strip renders no row whose value is missing" and "the Agenda card takes one column ... every grid slot is filled". Restored, run green.

Screenshots (1080x1920, Edge headless): .claude/work/121-cockpit-hero/shot-1080x1920.png (first), shot-1080x1920-b.png (final). Hero bottom is at y=505 (concept 495).

Calls made:
- The voice status chip is hidden in the portrait hero; its text goes to the voice button's title, and messages Rob must see go into the voice thread.
- Find is a fixed panel opened by a rail button or Ctrl K (same fuzzy finder, class `finder`).
- Layout: Agenda spans two rows beside News and Follow-ups; Finance sits beside Care.
- Hourly labels turned from "17:00" into "5 PM".

Not done: tests are static (no JS runtime), so behaviour was checked by screenshot only. The Agenda card still has some empty space under "Nothing on today." when the day is empty. The Follow-ups add field is slightly clipped inside its scrolling card. No stray round X exists in the page code.
