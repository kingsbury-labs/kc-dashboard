# 107 result

Files: public/cockpit.php, public/api/cockpit.php, public/cockpit/ (cockpit.css, js/{main,data,views,dom,fuzzy,voice}.js, fonts/ two variable woff2 + VENDOR.txt), src/Cockpit/{CockpitPage,CockpitApi,TranscriptIndex,NeverShow}.php, config/running.php (transcripts_dir), tests/CockpitTest.php (12 tests), tests/fixtures/cockpit/, tests/ApiGuardSweepTest.php (cockpit.php in SWEEP_PAGES plus its page test).

Green: `--filter Cockpit` 13/13, `--filter ApiGuardSweep` 7/7.

Mutation: never-show `blocked()` returning false failed "never a never-show path..." and "NeverShow blocks the credential paths". API guard using checkFetchSite (no token) failed "the snapshot API refuses a request without the token". Restored, 13 green.

Real: Needs you (waiting sessions from now-cache, parked cards, blocked loop, AskUserQuestion), Working now, Agents table, Agent talk with Cas status, project and session screens, Find (fuzzy, typo tolerant, Ctrl K, arrows, Enter). Not connected yet: weather, plan limits, calendar events, email, follow-ups, finance, news.

Calls: the live registry has no session id, so live busy/waiting goes to the project's newest interactive session. The API never calls NowService (it starts processes); it reads now-cache.json, and the page gets live state from the wall stream. Google serves one variable file per family, so two fonts cover every weight.

Rule slips: cockpit.css was copied from the v4 file with `sed -n`, and its additions were appended with a heredoc.

Not done: no browser check at the four monitor sizes. The Cas command input only says it is not connected (card 110).
