# 75. Project map and running strip: result

Files: `View.php` (strip, tiles, `View::projects`, `View::projectRows`; page takes `map` and `now` deps), new `api/projects.php`, `now.js`, `projects.js`, `app.css`, `Response.php` (JSON_INVALID_UTF8_SUBSTITUTE), `PageTest.php` (+1 Response test).

Tests: ProjectsApi 5 (foreign Host, token/navigation/POST refusals with no database open, order incl. mixed UTC offsets, no paths, invalid UTF-8 subject). ProjectsUi 6 (tiles and strip, payload escaped in every column and strip field, now.js grep, DomSinks and token on fetch, CSS). Both pass.

Mutations, each restored: raw subject failed ProjectsUi escaping; dirty dropped from sort failed both order tests; UTF-8 flag removed failed ProjectsApi UTF-8 and PageTest; database opened before guard failed both ProjectsApi refusal tests and ApiGuardSweep.

Calls: strip loops fall back to the projects table when no snapshot; page never scans. Sort in PHP, since date strings carry offsets.

Round two (brief widened): `PageUiTest.php` inventory now lists `now.js`, `projects.js`. `index.php` passes `map` (View::projectRows) and `now` (`data/now-cache.json` only, no scan). Full suite 201/201. `ops/http-check.php` exits 0, including the new `/api/projects.php`.

Live GET `/`: the strip renders from the snapshot (port :80 httpd.exe). The projects table has 0 rows, so the map is empty and no tile shows. collect-projects.php was not run against D:\Dev\htdocs.
