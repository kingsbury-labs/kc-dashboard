# 76b result

Files: View.php (command center, wall, new projectsPage, coreData), index/wall.php, new projects.php, Ports.php (filter), new SystemMonitor.php, NowService.php (`system` in now.php), running.php, all listed CSS/JS, new core.js. Tests: new CommandCenter (9), SystemMonitor (3); Hud, PageUi, ProjectsUi, Wall, NowApi (+3), ApiGuardSweep (projects.php page) updated; fixtures cim.txt, netstat-noise.txt, tasklist-noise.csv, command-center.json.

All seven filters pass. Full suite 366/366.

Mutations, each restored: Ports floor 1024 to 1 failed the two NowApi port tests. SystemMonitor cache window to 0 failed "two calls within 10 s". Agent name unescaped failed CommandCenter "renders escaped".

Calls: collectors are the scheduled tasks. Brief me, Run collectors, Talk and Executive briefing render disabled as "not connected": no endpoint exists yet (cards 79/80). Memory is "not connected". Wall body stays `class="wall"` for http-check.

Not done: `ops/http-check.php` fails. Apache is not running (nothing on 8787), so there was no live curl or screenshot. Owed: start Apache, rerun http-check, compare with sample 1. Also owed: http-check does not cover `/projects.php`. PHP's timezone is Europe/Berlin, so the no-JS clock and timeline times are off until JS runs.
