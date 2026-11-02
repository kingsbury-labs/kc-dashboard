# 131 result

Live tagger is `Classifier` (Mail::defaultTag). Client matching sits in `Relevance::byContact` and runs in both Classifier (after "not mine", before the graph walk) and Relevance. Unsure is folded into one pile: the API key stays `unsure`, it now holds every untagged, uncorrected row (`Relevance::unsorted`), labelled "Unsorted (n)" with both buttons.

Files: `src/Mail/ClientContacts.php` (new), `src/Mail/Relevance.php`, `src/Graph/Classifier.php`, `src/Collectors/Mail.php`, `src/Cockpit/CockpitDay.php`, `public/cockpit/js/views.js`, `config/collectors.php`, `tests/CockpitMailRelevanceTest.php` (5 tests), `tests/GraphTest.php` (1), `tests/CockpitDayTest.php`, `tests/fixtures/cockpit-mail/freemail/imap.json`.

Calls: the PII hook allows only example.* addresses, so tests pass a free-mail list (example.net/.org stand in for gmail/outlook) and a separate test checks the real list covers gmail.com, outlook.com and their subdomains. CockpitDayTest's IMAP-verb scan flagged ClientContacts' SELECT; I exempted that file and added a stricter check: exactly one SELECT, no write verbs. Auditors should look at this.

Mutation: exact match broken failed the free-mail client test and the GraphTest Classifier test; free-mail guard broken failed 4 MailRelevance tests; Unsorted keep broken failed 4. All restored green.

Not done: live capture. No credential and no `tests/tools/browser.mjs`, and a fixture cache would mean overwriting live `data/mail-cache.json`, which is outside my file list. Credential path, user `kc_dashboard_ro` and DB `daybook` in config are unconfirmed guesses.
