# 108 result

Files: src/Collectors/Weather.php (new), src/Cockpit/CockpitDaily.php (new), src/Cockpit/CockpitApi.php (adds `daily` to the snapshot, same guard path), public/api/cockpit.php, config/collectors.php (`weather` key), public/cockpit/js/views.js, public/cockpit/cockpit.css, tests/CockpitDailyTest.php (4 tests), tests/fixtures/cockpit-daily/.

Green: `--filter CockpitDaily` 4/4, `--filter Cockpit` 17/17.

Mutation: Weather deleting its cache before fetching failed "a failed weather fetch returns the last cached reading...". Usage sorting oldest file first failed "the snapshot API returns weather, news, finance and usage blocks..." and "usage picks the newest reading...". Restored, green.

Missing from real sources: kc-finance's alerts.json has no receivables or reserve facts (kinds today: payment-review, payment-split, todo, offsite-stale), so those show a dash; no due-dated invoices either. No `rate_limit_event` found in this project's or Sembr's loop pass files, so plan limits will say "No reading yet" until a loop run writes one.

Not done: Weather is not wired into bin/collect-facts.php (not in my file list), so the cache is never filled in production. It needs one line there: `new Weather(new CurlHttpClient(), $root . '/data/' . $cfg['weather']['cache'], $cfg['weather']['latitude'], $cfg['weather']['longitude'])`. No browser check.
