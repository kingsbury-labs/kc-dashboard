# 111 result

Files: src/Cockpit/CockpitApi.php (cached/stale/refresh, briefLabel, needs newest first), src/Cockpit/CockpitDaily.php (tolerant finance read plus alert lines, usage also scans subagent transcripts), src/Cockpit/CockpitDeps.php (new, deps shared by the API and the collector), public/api/cockpit.php (answers from data/cockpit-cache.json; a cache past 20 s is served, then rebuilt after the response), bin/collect-facts.php (Weather collector, cache refresh), public/cockpit/js/data.js, views.js, main.js, cockpit.css, tests/CockpitFixesTest.php (6 tests).

Snapshot time: before 5.4 to 5.9 s (brief). After, warm 16 to 27 ms over 6 curl runs, including the calls just past the TTL. A cold build with no cache file at all takes 5.6 s, once; collect-facts primes the cache.

Green: `--filter Cockpit` 23/23.

Mutation: forcing a rebuild on every call failed "the snapshot API answers from cache without re-indexing". Throwing on a fact with missing fields failed "a finance file with facts missing fields returns the good facts". Restored both, green.

Calls: any cache, however old, is served and then rebuilt, so the page never waits 10 s after Rob has been away. The JS checks are source-text tests (same style as CockpitTest), not run in a browser.

Not done: no browser look. No real `rate_limit_event` exists in any transcript. The ones in C--Users-roban and kc-finance are test fixture text inside tool output. So plan limits still say "No reading yet", now with empty bars. The usage scan still stops after the 40 newest files.
