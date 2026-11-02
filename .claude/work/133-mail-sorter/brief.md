# 133. Mail sorter uses the client pattern; Unsorted always visible (Risky)

## Objective
The cockpit mail sorter places client mail by the rules in Rob's client pattern, in priority order, and tells mail *from* a client apart from mail *about* a client. At 1080x1920 the mail card shows its Unsorted pile without scrolling inside the card. Role: implementer, Opus; the loop runs a security audit and a Fable audit.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`, `C:\Users\roban\.claude\pm\traps.md`
- `C:\Users\roban\.claude\pm\mail-sorter-pattern.md`: the spec. Rules 1 to 6 in priority order, each with its false-match risk, the never-a-client sender list, and which rule catches whom.
- Card 131, which this builds on: `.claude/work/131-client-mail/brief.md` and `result.md` (commit 5d8ce5d): `src/Mail/ClientContacts.php`, `src/Mail/Relevance.php` (`byContact`), `src/Graph/Classifier.php` (the live tagger), `src/Collectors/Mail.php`, `src/Cockpit/CockpitDay.php`, `public/cockpit/js/views.js`.
- The live review of 131: `.claude/work/131-client-mail/live/progress.md` and its captures `full.png`, `mail-card.png`, `mail-card-unsorted-open.png`.

## Context
Rob's ask, from the PM: "Mail sorter uses the client pattern; Unsorted always visible."

Build rules 1 to 4 and 6; rule 5 only as a tie-breaker:
1. Exact address from `_CONTACTS.md` on From, To or Cc (131 matches From only; extend it). Rob's studio inbox receives most client mail, so To and Cc count.
2. The client's own non-free-mail domain on From, To or Cc, excluding system local parts (wordpress, noreply, no-reply, billing, notifications, and kin, in one easy-to-extend constant).
3. Thread claim: if any message in a thread is from Rob to an address matched by rule 1 or 2, every message in that thread belongs to that client. If the collector does not already keep thread identity (Message-ID, In-Reply-To, References, or Gmail's thread id) or Rob's sent mail, add what is needed read-only (header fetch with BODY.PEEK, EXAMINE only), and say what you added.
4. The client's site domain or business name in the subject or the first lines of the body, tagged `about` (not `from`), so it goes to the project pile and not the reply pile. Site domains come from the client files the cockpit already reads (`config/clients.php` and the client `PROJECT.md` files), not a hard-coded list.
5. Display name: only to break a tie, and only when the address is free mail and the thread also hits rule 3 or 4. Never on its own.
6. Contact-form relay: mail from the studio site's own noreply sender whose subject is "New project inquiry from <Name>", matched against the Name column of `_PIPELINE.md`.

Never a client: payment, hosting and platform senders and newsletters, as the pattern lists; rule 4 may still tag them `about`, never `from`.

Layout bug from the live review: at 1080x1920 the mail card is 448px tall with `overflow-y: auto`, content 1067px, so it shows 3 of 6 rows and Unsorted sits below the fold inside the card. Unsorted must be visible without scrolling inside the card at 1080x1920 (for example Unsorted first, or its own visible header with a count, and the card sized to its rows). Mobile first still holds.

Data rules, unchanged from 131: `D:\Dev\htdocs\kingsburycreative\clients\_CONTACTS.md` is gitignored PII, read at run time only. Never commit it, never copy its values into a tracked file, fixture, log, cache, test output or `result.md`, and never print its rows while working. Tests use fake fixtures with `example.*` addresses. The cache stores no mail body. The local model only. Mail stays read-only.

## Start here
- `Relevance::byContact` and the rule order in `Classifier` are the seams. Put each rule in its own small pure function so each can be tested alone, and record which rule placed a row (`rule: 1..6`, `kind: from|about`) on the row.
- Done-test command: `c:\xampp\php\php.exe tests/run.php --filter MailRelevance`; also `--filter CockpitDay`, `--filter Graph`, `--filter Cockpit` for anything touched.
- Live check, after the tests pass: refresh the mail cache by the collector's own normal path (find how the dashboard runs collectors, in `bin/` and the registered tasks; never hand-write `data/mail-cache.json`, never open a visible window), then a headless capture at 1080x1920 of the cockpit (Playwright with its own profile, never the real Chrome; never python or cmd from Bash), saved in this folder, showing mail from Henike, Brauwerk or Chris Adams under their client names and the Unsorted pile visible without scrolling inside the card.

## Files you may touch
- `src/Mail/ClientContacts.php`
- `src/Mail/Relevance.php`
- `src/Mail/MailRules.php` (new, if you split the rules out; one file, no others)
- `src/Graph/Classifier.php`
- `src/Collectors/Mail.php`
- `src/Cockpit/CockpitDay.php`
- `public/cockpit/js/views.js`
- the stylesheet under `public/cockpit/` that holds the mail card
- `config/collectors.php` (constants only, no secret)
- `tests/CockpitMailRelevanceTest.php`, `tests/CockpitDayTest.php`, `tests/GraphTest.php`, `tests/CockpitPortraitTest.php`, `tests/CockpitFitTest.php`, `tests/fixtures/cockpit-mail/` (invented names and `example.*` addresses only)

## Done-test
`c:\xampp\php\php.exe tests/run.php --filter MailRelevance` passes, with tests proving, on fake fixtures:
- rule 1 matches an exact contact address on To and on Cc, not only From;
- rule 2 matches a client's own domain and refuses `wordpress@`, `noreply@` and `billing@` on that domain, and never a free-mail domain;
- rule 3 puts every message of a thread Rob replied to under that client, including one from an unknown sender;
- rule 4 tags a platform notice naming a client's site domain as `about`, never `from`, and a payment or hosting sender is never `from`;
- rule 6 matches "New project inquiry from <Name>" from the studio noreply sender to the pipeline Name;
- rule 5 alone never places a row; it only breaks a tie;
- the priority order holds when two rules disagree (a test per adjacent pair that can conflict);
- every placed row carries its `rule` and `kind`;
- no body text and no contact address list is written to `mail-cache.json`;
- the mail card at the portrait size puts Unsorted where it shows without scrolling inside the card (`--filter Cockpit`).

Mutation check through `node C:\Users\roban\.claude\bin\project-test.cjs`: break the To/Cc match, the system-sender exclusion, the thread claim and the about/from split in turn, run filtered, name the tests that failed each time, restore, green.

## Boundaries
No commit, no push, no install. Mail stays read-only (EXAMINE, BODY.PEEK; never send, flag, move or delete). No remote model, no external API. `_CONTACTS.md` values never leave memory. No real network calls in tests. No window opens. PHP 8.2 only. `project.md`'s Never list applies.

## Progress
Keep `progress.md` beside this file from your first step, one line per step that changes a file or finishes a unit of work: done, files, next, anything half-written. Already there? You are resuming: read it and check the disk against it first.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests added, the mutation result naming the tests that failed, what you added to the collector for threads, the capture paths and what they show, and any call made and why. Say what was not done.
