# 122 result

Files changed: public/cockpit/cockpit.css, public/cockpit/js/views.js, tests/CockpitHeroTest.php.
Tests added (CockpitHeroTest): finance line has no kind cell and no dash; desktop sizes (no font above 72px, clock 72px, card text 14px, Cas bar and Google 44px). Two older assertions updated for the new finance markup and grid areas. `--filter Cockpit`: 67 passed.

Mutation: clock set back to 148px. Failed: CockpitHeroTest "desktop sizes: nothing in the 1080 x 1920 block is above 72px, the clock is 72px...". Restored, green.

Screenshot: D:\Dev\htdocs\_kc-dashboard\.claude\work\122-cockpit-desktop-sizes\shot-1080x1920-b.png (first try shot-1080x1920.png had dead space, replaced).

Calls made:
- Re-paired grid areas to "agenda news" / "follow finance" / "care care", with News capped at 306px and Finance at 232px (scroll), so the hero's returned height goes to the four list cards (about 410px each now, was 310).
- Finance line CSS columns changed from 3 to 2.

Not done: Agenda still has about 55px under "Nothing on today." when the day is empty (the card is one grid row beside News, which is taller). Finance scrolls (last line "Todo" is clipped at the card edge). Tests are static; behaviour checked by screenshot only.
