# 131. Client mail shows in the cockpit (Risky)

## Objective
Mail from Rob's clients appears in the cockpit mail card under the client's name, even when the client writes from a free-mail address (gmail.com and kin). Mail that matches nothing is no longer hidden: it shows in an "Unsorted" pile with the existing "Not mine" and "Project..." buttons. Client contacts come read-only from Rob's client files in `D:\Dev\htdocs\kingsburycreative\clients\`, with no database and no new credential. Role: implementer, Opus. Then a `security-auditor` pass and an independent Fable audit of the diff (the loop runs both).

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `C:\Users\roban\.claude\pm\traps.md`
- `D:\Dev\htdocs\kingsburycreative\CLAUDE.md` (the section on `clients/`: `_PIPELINE.md` is the one CRM, `_CONTACTS.md` holds the contact details and is gitignored PII)
- The WIP on branch `wip/131-client-mail` (commit `063ab6e`), and its `result.md` there: `git show wip/131-client-mail:.claude/work/131-client-mail/result.md`
- `D:\Dev\htdocs\_kc-dashboard\src\Clients\PipelineParser.php` and `DashboardParser.php` (the existing readers of the clients folder; style to match), `config/clients.php` (`dir` already names the clients folder)
- `D:\Dev\htdocs\_kc-dashboard\src\Mail\Relevance.php`, `src\Graph\Classifier.php` (the live tagger, per the WIP), `src\Cockpit\CockpitDay.php:75-115`, `public\cockpit\js\views.js:285-340`
- `D:\Dev\htdocs\_kc-dashboard\tests\CockpitMailRelevanceTest.php`, `tests\ClientsParserTest.php`

## Context
Rob, 2026-11-01, verbatim: "how come my client stuff isn't in there? Josh? David? Chris? Julie?"

This card was built once against Daybook's MySQL `clients` table and parked before the gate. Rob, 2026-11-01: Daybook is not used and is closed. The card must not read Daybook in any form: no PDO, no MySQL user, no credential file, no Daybook path. Everything else in the WIP (matching, free-mail guard, Unsorted pile, tests) stays unless it depends on Daybook.

Cause of the bug: `Relevance::tag` / `Classifier` go (1) Rob's corrections in `data/mail-senders.json`, (2) sender domain on the entity list, (3) the local model, which may only answer an entity name; anything else is null and is dropped. Clients writing from gmail.com match nothing.

The client files (checked by the PM, header rows only):
- `clients/_CONTACTS.md`: a markdown table `| Name | Business | Email | Phone |`, one row per business. Gitignored PII (`kingsburycreative/.gitignore:44`). Read it at run time only. Never commit it, never copy its values into a tracked file, fixture, log, cache, test output or `result.md`. Never print its rows to the terminal while working (no `cat`, no `head`, no grep that prints a row); its shape is the header line above.
- `clients/_PIPELINE.md`: the one CRM, table `| Name | Business | Town | Stage | Intro Price | Next Action | Notes |`, no contact details. Already parsed by `PipelineParser`.
- `clients/_DASHBOARD.md` and `clients/<client>/PROJECT.md`: the per-client files the cockpit already reads through `DashboardParser`.

## Design (decided, do not re-open)
1. Replace the WIP's `src/Mail/ClientContacts.php` Daybook reader with a file reader: `ClientContacts` returns `{name, email}` for each `_CONTACTS.md` row that has an email (name = Business if set, else Name), lowercased and trimmed; a cell with several addresses (comma, semicolon or space separated) gives one entry each. It takes the clients dir from `config/clients.php`'s `dir`, and a path override for tests. Clients `_PIPELINE.md` marks lost or closed may be left out; say which rule you used. A missing or unreadable `_CONTACTS.md` returns `[]`, logs one line naming the file but no values, and mail still works (domain match and Unsorted).
2. Remove every Daybook trace from the WIP: the PDO code, the credential path, `kc_dashboard_ro`, the `daybook` DB name in `config/collectors.php`, and the CockpitDayTest SELECT exemption for ClientContacts (no SQL remains, so the IMAP-verb scan needs no exemption).
3. Matching, unchanged from the WIP: lowercase exact full-address match, after Rob's corrections and before the domain match. Free-mail domains (one easy-to-extend constant: gmail.com, googlemail.com, outlook.com, hotmail.com, live.com, msn.com, yahoo.com, ymail.com, icloud.com, me.com, mac.com, aol.com, proton.me, protonmail.com, gmx.com, mail.com, rogers.com, bell.net, sympatico.ca, shaw.ca, telus.net) never enter the domain map. A client on their own domain also adds that domain to the entity list. A subdomain such as `x.gmail.com` never matches.
4. Unsorted, unchanged: any row with no project and no "not mine" correction shows in an `Unsorted (n)` pile with both buttons; a correction to null hides it; cached rows from before the change carry `project: null` and show too.
5. Privacy: the cache stores no mail body. Client names and addresses stay in memory: not in `data/mail-cache.json`, not in logs, not in any API response except the matched project name and the sender address already returned. Nothing goes to an external API. The local model only; its prompt carries entity names only, no client addresses, and at most `Relevance::CHARS` (1,000) characters of body.
6. A model answer that is not an entity name stays null and lands in Unsorted.

## Start here
- First step: bring the WIP's product files onto this tree: `git checkout wip/131-client-mail -- src config public tests` (not `.claude/work`). Then `git diff --stat` should show the WIP's 11 product files. Do not commit.
- `src/Mail/ClientContacts.php` (the WIP's 50 lines) is the file to rewrite; `Relevance::byContact` is the seam that consumes it.
- Tests use a fake `_CONTACTS.md` fixture under `tests/fixtures/cockpit-mail/` with made-up names and `example.*` addresses only (the PII hook allows only those; the WIP already uses example.net/.org as free-mail stand-ins). Never a real client row.
- Done-test command: `c:\xampp\php\php.exe tests/run.php --filter MailRelevance`; also `--filter CockpitDay`, `--filter Graph` and `--filter ClientsParser` for anything touched.

## Files you may touch
- `src/Mail/ClientContacts.php` (new)
- `src/Mail/Relevance.php`
- `src/Graph/Classifier.php`
- `src/Collectors/Mail.php`
- `src/Cockpit/CockpitDay.php`
- `public/cockpit/js/views.js`
- the one stylesheet under `public/cockpit/` that holds `.email-unsure` (for the pile only)
- `config/collectors.php` (free-mail list only; no Daybook keys)
- `config/clients.php` (only if a contacts file key is needed)
- `tests/CockpitMailRelevanceTest.php`, `tests/CockpitDayTest.php`, `tests/GraphTest.php` and `tests/fixtures/cockpit-mail/` (invented names and `example.*` addresses only)

## Done-test
`c:\xampp\php\php.exe tests/run.php --filter MailRelevance` passes, with tests proving:
- a free-mail client address (from the fake `_CONTACTS.md`) maps to its client by exact address;
- a cell with two addresses gives two entries;
- a free-mail stranger (same domain, different address) lands in Unsorted, not hidden, and the model prompt holds no client address;
- a whole free-mail domain never maps to a client, including via a client whose contact is on that domain and via a subdomain; a separate test checks the real list covers gmail.com and outlook.com;
- Rob's corrections still win, both a project and null (null hides it from Unsorted too);
- a client on their own domain still maps by domain;
- a missing `_CONTACTS.md` returns no entries, logs no values, and mail still works;
- no body text and no client address list is written to `mail-cache.json` (read the cache file after a run and assert);
- no file under `src/` or `config/` names Daybook, PDO or a credentials path (a source scan test).

Mutation check through `node C:\Users\roban\.claude\bin\project-test.cjs`: break the exact-address match, the free-mail guard and the Unsorted keep in turn, name the tests that failed each time, restore, green.

Live check (UI card): capture the cockpit mail card at 1080x1920 portrait on the live local URL with headless Playwright (`tests/tools/browser.mjs` if present; never Chrome, never a visible window), fresh load, saved in this folder. It shows client mail under its client name and an Unsorted pile whose rows have both buttons. If you cannot capture without overwriting live `data/mail-cache.json`, say so; the PM closes the card only after seeing the real one.

For Rob: list in `result.md` the client folders under `clients/` (folder names only, which are already in tracked files) that have no email in `_CONTACTS.md`. Never their contact values.

## Boundaries
No commit, no push, no install. Never read Daybook, its code, its `.env` or any credential. Mail stays read-only (EXAMINE, BODY.PEEK; never send, flag, move or delete). `_CONTACTS.md` values never leave memory (rule above). No real network calls in tests. No window opens (`windowsHide: true` on anything spawned). Mobile first; skeleton rules unchanged for the card. `project.md`'s Never list applies. PHP 8.2 only.

## Progress
Keep `progress.md` beside this file from your first step, one line per step that changes a file or finishes a unit of work: done, files, next, anything half-written. Already there? You are resuming: read it and check the disk against it first.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests added, the mutation result naming the tests that failed, what became of the Unsure pile, the client folders with no email, and any call made and why. Say what was not done, including the live capture if it was not taken.
