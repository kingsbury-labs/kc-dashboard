# 76 result

Files changed: public/assets/hud.css (new), public/assets/orb.js (new), src/View.php, src/Running/NowService.php (JSON_INVALID_UTF8_SUBSTITUTE only), public/index.php, tests/HudTest.php (new, 4 tests), tests/ProjectsUiTest.php (2 tests added), tests/PageUiTest.php (sink check).

Full suite through the runner: 208 passed, 0 failed.

Mutations, each restored then green:
- View with the raw snapshot instead of cleanSnapshot: ProjectsUiTest "arrays where strings belong" failed.
- hud.css with a max-width query, and separately uppercase h2 at 0.12em: the HudTest hud.css test failed.
- readSnapshot without the cap check: ProjectsUiTest "over the byte cap" failed.

Calls:
- Cap logic is `View::readSnapshot` (256 KB, over-cap or bad file is no snapshot), since index.php emits and cannot be tested directly; index.php calls it.
- PageUiTest sink check now walks public/assets recursively, runs DomSinks per folder, and fails when no JS is found. DomSinks itself only globs one folder, so I loop it per directory. All old assertions kept.
- orb.js wires itself via a MutationObserver on #answers (no app.js change).
- Earlier slip: the first ProjectsUiTest test was appended with a heredoc; later edits used Edit/Write.

Not done: nothing outstanding.
