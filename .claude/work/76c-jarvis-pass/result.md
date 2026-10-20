# 76c result

Final screenshot (1432x1073, headless Chrome, fresh profile): `C:\Users\roban\AppData\Local\Temp\claude\D--Dev-htdocs--kc-dashboard\0569766f-7fb9-484f-94fb-8b58510724de\scratchpad\final-1432.png`. Five rounds of screenshots. Phone checked at 375 with no overflow, wall at 1920.

Files: View.php, NowService.php (`schedule()` reads register-tasks.ps1), app.css, hud.css, wall.css, orb.js, core.js, voice.js, CommandCenterTest, WallTest.

Tests: 4 new in CommandCenter (feed headlines, scheduled timeline, LLM tile per model, voice panel held to the rail). The agent regex and the Wall fixture now name their schedule. Filters pass, the full suite passes 399/399, and `ops/http-check.php` exits 0.

Mutations, each restored and rerun green: Worker exclusion `'worker'` failed timeline. Next run `+1` removed failed timeline ("in 15 min"). Connected count ignoring `present` failed LLM ("zero connected").

Calls: Task Scheduler has none of the six jobs registered (`tasks` is empty). They show as "off" collectors, plus a WARN row in the feed. No headlines are collected yet, so the feed fills with the newest commits from the project map.

Still differs from sample 1: there are no news rows until the collectors run. The clock is a little left of centre because the search field needs the room. Agents and feed scroll inside their panels. The rail has empty space below the nav. Assets carry no cache-busting, so an open tab needs a hard refresh to pick this up. Times rendered without JS use PHP's Berlin timezone until core.js corrects them.
