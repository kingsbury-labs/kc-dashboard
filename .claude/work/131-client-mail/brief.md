# 131. Client mail shows in the cockpit (Risky)

## Objective
Mail from Rob's clients appears in the cockpit mail card under the client's name, even when the client writes from a free-mail address (gmail.com and kin). Mail that matches nothing is no longer hidden: it shows in an "Unsorted" pile with the existing "Not mine" and "Project..." buttons. Role: implementer, Opus. Then a `security-auditor` pass, and an independent second-model audit (Fable) of the diff, both before the PM closes it.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `C:\Users\roban\.claude\pm\traps.md`
- `D:\Dev\htdocs\_kc-dashboard\src\Mail\Relevance.php` (whole file, about 190 lines)
- `D:\Dev\htdocs\_kc-dashboard\src\Collectors\Mail.php` (lines 30-120: how tags are cached and rows built)
- `D:\Dev\htdocs\_kc-dashboard\src\Graph\Classifier.php` (card 117's tagger; it has its own `unsure` pile)
- `D:\Dev\htdocs\_kc-dashboard\src\Cockpit\CockpitDay.php:75-115` (`mail()`: drops untagged rows, builds `rows` and `unsure`)
- `D:\Dev\htdocs\_kc-dashboard\public\cockpit\js\views.js:285-340` (mail card, the `Unsure` details pile, `emailFix` buttons)
- `D:\Dev\htdocs\_kc-dashboard\tests\CockpitMailRelevanceTest.php` (style to match; fixtures in `tests\fixtures\cockpit-mail\`)
- Daybook schema only: `c:\xampp\htdocs\daybook\database\migrations\2026_01_07_000002_create_clients_table.php`. Do NOT open Daybook's `.env`.

## Context
Rob, 2026-11-01, verbatim: "how come my client stuff isn't in there? Josh? David? Chris? Julie?"

Cause: `Relevance::tag` goes (1) Rob's corrections in `data/mail-senders.json`, (2) sender domain on the entity list, (3) the local model, which may only answer an entity name; anything else is null and `Relevance::apply` / `CockpitDay::mail` drop it. Clients writing from gmail.com match nothing.

Daybook facts read from its migrations: the `clients` table has `id`, `company_name` (nullable), `contact_name`, `email` (one address, string), `is_active`, `stage`. No contacts table was found, so one email per client. The live DB driver and name are set in Daybook's `.env`, which was not read, so they are unconfirmed.

Already there: the mail card shows an `Unsure` pile (mail the graph and model could not place, `rows[].unsure`). Find out which tagger the live collector uses (`Classifier` or `Relevance`) before editing and make the change in the one that runs. The new "Unsorted" pile covers every row that matches nothing, including ones with no model answer; fold `Unsure` into it or keep both, whichever leaves one pile Rob sees, and say which in `result.md`.

## Design (decided, do not re-open)
1. New read-only source of client contacts, `src/Mail/ClientContacts.php`: returns a list of `{name, email}` (name = `company_name` if set, else `contact_name`; active clients only). Narrowest access: a MySQL user with column-level SELECT on `clients(company_name, contact_name, email, is_active)` only, its password in `D:\Dev\htdocs\.credentials\kc-dashboard-daybook-ro`, read at run time by path, never logged or put in a fixture. The PM provisions the DB user and the file; the worker writes the reader (PDO, `ATTR_ERRMODE_EXCEPTION`, prepared statement, one SELECT, 2 s connect timeout, host 127.0.0.1) and tests it with a fake source (a Closure returning rows). If the credential file is missing or the query fails, the reader returns `[]`, logs one line with no values, and mail still works (domain match and Unsorted). Never read or require Daybook's code, `.env` or full credentials.
2. Matching: lowercase exact full-address match, checked after Rob's corrections and before the domain match. Free-mail domains (gmail.com, googlemail.com, outlook.com, hotmail.com, live.com, msn.com, yahoo.com, ymail.com, icloud.com, me.com, mac.com, aol.com, proton.me, protonmail.com, gmx.com, mail.com, rogers.com, bell.net, sympatico.ca, shaw.ca, telus.net, in one constant that is easy to extend) never enter the domain map, whatever client has them. A client on their own domain (`@acme.ca`) also adds that domain to the entity list, as now. A client address on a free-mail domain is matched only as the full address, and a subdomain such as `x.gmail.com` never matches.
3. Unsorted: any row with no project and no "not mine" correction is kept and shown in an `Unsorted (n)` pile with `Not mine` and `Project...` buttons. A correction to null still hides it. Cached rows from before the change carry `project: null` and must show too (no re-read of old mail needed).
4. Privacy: the cache stores no mail body (existing rule, keep it). Client names and addresses stay in memory and on this machine: not in `data/mail-cache.json`, not in logs, not in any API response except the matched project name and the sender address already returned. Nothing goes to an external API. The local model prompt carries entity names only (no client email addresses) and at most `Relevance::CHARS` (1,000) characters of body.
5. A model answer that is not an entity name stays null and lands in Unsorted.

## Start here
Nothing is edited yet; the line ranges above were read by the PM. Reuse `Relevance::senders`, `Relevance::correct`, `Relevance::apply`, `CockpitDay::mail`. Done-test command:
`c:\xampp\php\php.exe tests/run.php --filter MailRelevance` (the runner filters on `file: name`, so the existing `CockpitMailRelevanceTest.php` matches). Also run `--filter CockpitDay` and `--filter Graph` for anything you touched.

## Files you may touch
- `src/Mail/ClientContacts.php` (new)
- `src/Mail/Relevance.php`
- `src/Graph/Classifier.php` (only if it is the live tagger)
- `src/Collectors/Mail.php`
- `src/Cockpit/CockpitDay.php`
- `public/cockpit/js/views.js`
- the one stylesheet under `public/cockpit/` that holds `.email-unsure` (for the pile only)
- `config/collectors.php` (credential path and free-mail list only; no secret value)
- `tests/CockpitMailRelevanceTest.php`, `tests/CockpitDayTest.php`, `tests/GraphTest.php` and `tests/fixtures/cockpit-mail/` (invented names and addresses only, never real clients)

## Done-test
`c:\xampp\php\php.exe tests/run.php --filter MailRelevance` passes, with new tests proving:
- a free-mail client address maps to its client by exact address;
- a free-mail stranger (same domain, different address) lands in Unsorted, not hidden, and the model prompt holds no client address;
- a whole `gmail.com` (and `outlook.com`) domain never maps to a client, including via a client whose contact is on that domain and via a subdomain;
- Rob's corrections still win, both a project and null (null hides it from Unsorted too);
- a client on their own domain still maps by domain;
- an unreachable or missing contact source returns no entries and mail still works;
- no body text and no client address list is written to `mail-cache.json` (read the cache file after a run and assert).

Mutation check through `node C:\Users\roban\.claude\bin\project-test.cjs`: break the exact-address match, the free-mail guard and the Unsorted keep in turn, name the tests that failed each time, restore, green.

Live check (UI card): after the PM provisions the read-only user, capture the cockpit mail card at 1080x1920 portrait on the live URL with headless Playwright (`tests/tools/browser.mjs` if present; never Chrome, never a visible window), fresh load, saved in this folder. It shows client mail under its client name and an Unsorted pile whose rows have both buttons. Without the credential, capture with a fixture cache and say so; the PM closes the card only after seeing the real one.

## Boundaries
No commit, no push, no install. Mail stays read-only (EXAMINE, BODY.PEEK; never send, flag, move or delete). Never print or read a secret value: no `.env`, no `.credentials` file contents; test a missing credential file by path existence only. No real network calls in tests. No SQL write against Daybook; do not edit Daybook. No window opens (`windowsHide: true` on anything spawned). Mobile first; skeleton rules unchanged for the card. `project.md`'s Never list applies. PHP 8.2 only.

Audit: after the implementer finishes, the PM runs `security-auditor` on the diff (SQL scope, credential handling, anything that could leak a client address to a response, log or cache), then a Fable audit of the same diff by a reviewer who has not seen the implementer's reasoning. Findings are fixed before the card passes.

## Progress
Keep `progress.md` beside this file from your first step, one line per step that changes a file or finishes a unit of work: done, files, next, anything half-written. Already there? You are resuming: read it and check the disk against it first.

## Report
Write `result.md` beside this file, under 200 words: files changed, tests added, the mutation result naming the tests that failed, which tagger was live and what became of the Unsure pile, and any call made and why. Say what was not done, including the live check if the credential was missing.
