# 62. Page UI shell: result

Round one built the page, the clients API and parsers, elevation, the shutdown function, emit, DomSinks, the fixtures and five test files. http-check's 0x08 byte became `\b`, and the file got a CLI guard.

Mutations, round one: (a) innerHTML in app.js was refused ("Create RCE Surface"). I restored it and did not retry, so it is owed. (b) The link filter: ClientsApi "javascript: link is dropped" and ClientsParser "links keep only http and https" failed. (c) The shutdown function: PageTest "a true fatal" failed. All restored and green.

## Round two

1. Elevation caches its result in `data/elevation.json` and runs whoami at most once a minute. The clock and the path are injected. New ElevationTest tests: "two page renders within a minute start whoami once" and a future or malformed stamp. checkRead refuses `Sec-Fetch-Site` `cross-site` and also `same-site` (another port on 127.0.0.1) unless the mode is navigate. Live: an img-style request gets 403, a navigate gets 200.
2. http-check names `/api/ask.php` and exits 1 if that list does not match `public/api/`. All three ask.php write checks ran and passed.
3. The CSP adds trusted types and `base-uri 'none'`, asserted in PageTest. DomSinks gains the five new sinks, with planted and clean samples.
4. Matched on SIDs. New fixtures: `system.txt` and `high-de.txt`.

Mutations: a cache that never hits failed "start whoami once". Letting same-site through failed the new RequestGuard test. Both restored. Full suite: 140 passed.
