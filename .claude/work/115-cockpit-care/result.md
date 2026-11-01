# 115 result

Files: new `src/Collectors/Care.php` (clients.json + UptimeRobot getMonitors POST + `gh issue list` + SslExpiry fetcher, writes `data/care-cache.json`, no facts), new `src/Cockpit/CockpitCare.php` (status, incidents); wired in `CockpitApi.php` (`care` key), `CockpitDeps.php`, `config/collectors.php` (`care` block, key by path), `config/executables.php` (`gh`, real path checked), `bin/collect-facts.php`. UI: Care tab (`#/care`, `#/care/<key>`), Care card on the overview, red banner, one Notification per incident id (localStorage, try/catch), permission from a button only (`views.js`, `main.js`, `dom.js`, `cockpit.css`).

Tests: `tests/CockpitCareTest.php`, 5 tests, fixtures in `tests/fixtures/cockpit-care/` (key file is a fake value). `--filter CockpitCare` green.

Mutations: down treated as up failed "care block lists each client" and "a down monitor makes that client red". Accepting another gh subcommand (only args after the first compared) first passed; added `pr`/`run` cases, then "runs only gh issue list" failed. Both restored, green.

Calls: the key is added to Log redaction inside the collector before the request. Alerts are matched to a client by key or domain in the issue title.

Not done: `node --check` on the JS was blocked by credential-read-blocker (false positive), so JS syntax is unchecked; no screenshot.
