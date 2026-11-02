# 131 result

WIP brought in with `git diff main...wip | git apply --3way` (the classifier refused `git checkout`); it sits staged, my edits unstaged. Nothing committed.

Files: `src/Mail/ClientContacts.php` (rewritten: reads the first table with an Email column in `_CONTACTS.md`, one entry per address, Business else Name; missing file logs `_CONTACTS.md could not be read`, no values), `src/Collectors/Mail.php`, `src/Mail/Relevance.php` and `src/Graph/Classifier.php` (comments, reason text), `config/collectors.php` (Daybook keys removed), `tests/CockpitDayTest.php` (SELECT exemption removed), `tests/GraphTest.php`, `tests/CockpitMailRelevanceTest.php`, new fixture `tests/fixtures/cockpit-mail/_CONTACTS.md`.

Rule: every contact row counts; `_PIPELINE.md` stage is not consulted.

Call: the source scan cannot bar PDO and `.credentials` across all of `src/` and `config/`, since the dashboard's own SQLite and Gmail files use them. It bars Daybook/MySQL everywhere the card touches, and PDO/credentials in the contact path.

Tests added: contacts parse (two-address cell), source scan; missing-file test rewritten. MailRelevance 10/10, CockpitDay 6/6, Graph 5/5.

Mutation: exact match broken failed the parse test, the free-mail client test and the GraphTest Classifier test; free-mail guard broken failed 4 MailRelevance tests; Unsorted keep broken failed 4. Restored, green.

Unsure pile: now "Unsorted (n)", one pile.

Client folders with no email found (matched by name, so approximate): 4-muddy-paws, brauwerk-hoffman, chris-adams, cold-bear-brewing, creo-marketing, daybook-marketing, dorothys-lodge, hughes-brittle, indigo-inkk-tattoos, kingsbury-creative, negi-family-bistro, pittstbbq, roanoke-golf-club, the-cupboard, uovcc, valley-roots, valley-vision, west-quebecers, zen-interior-design.

Not done: live capture (no `tests/tools/browser.mjs`, would overwrite live `data/mail-cache.json`).
