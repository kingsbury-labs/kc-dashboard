# 109. Cockpit calendar, email summaries and follow-ups (Risky)

## Objective
The cockpit's Agenda, Email summaries and Follow-ups panels show Rob's real
day: this month's calendar with today's events, the newest inbox threads each
summed up in one line, and follow-ups drawn from threads that need a reply plus
a short local list he can tick off. Mail is read-only and never leaves the PC.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md` (Never list: EXAMINE and BODY.PEEK only)
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 109
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\107-cockpit-live\result.md` and `108-cockpit-daily\result.md`
- `D:\Dev\htdocs\_kc-dashboard\src\Cockpit\`, `public\cockpit\js\`, `src\Net\` (HTTP client), `src\Collectors\Collector.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Http\Token.php` (how a secret file is loaded and redacted from logs)

## Context
- Access (Rob saved both, 2026-11-01): `D:\Dev\htdocs\.credentials\kc-dashboard-gcal-ical-url`
  (Google Calendar secret iCal address) and
  `D:\Dev\htdocs\.credentials\kc-dashboard-gmail-app-password`. Config names
  them by path only; values are read at run time, added to `Log`'s redaction,
  never logged, never in a test or fixture. Gmail account: robandtherockets@gmail.com (Rob's best guess, 2026-11-01:
  "probably my normal email"); it lives in config as `gmail_user`. If LOGIN is
  refused at run time, the panel says "Gmail sign-in refused: check the app
  password's account" and nothing else breaks.
- XAMPP's PHP 8.2 has no imap extension and nothing may be installed. Write a
  small IMAP client over `stream_socket_client('tls://imap.gmail.com:993')`:
  LOGIN, EXAMINE INBOX (never SELECT), UID SEARCH, UID FETCH with BODY.PEEK and
  ENVELOPE only, LOGOUT. No STORE, COPY, MOVE, EXPUNGE, APPEND or SELECT exist in
  the code at all (a test greps for them).
- Summaries: the local Ollama at `http://127.0.0.1:11434` (`qwen3.5:9b-q4_K_M`),
  one line each from sender, subject and the first 2,000 characters of the text
  part; it also answers reply-needed yes/no. Cached by message UID; mail text is
  never stored, only the summary, sender, subject, date and Gmail link.
  Ollama down: show sender and subject only.
- Calendar: parse the iCal feed in PHP (no library): VEVENT DTSTART/DTEND
  (with TZID and all-day), SUMMARY, LOCATION, and RRULE DAILY/WEEKLY/MONTHLY
  with COUNT/UNTIL/BYDAY expanded inside the shown month; anything else is
  shown once and noted in result.md. Cached, refreshed every 15 minutes.
- Follow-ups: reply-needed threads plus a local list in `data/` with a
  token-guarded write (checkWrite) to add or tick off an item.
- All three refresh on the collector schedule, never on page load.

## Files you may touch
- `src/Cockpit/` and `src/Mail/` (new: ImapReader.php, read-only)
- `src/Collectors/` (new Calendar and Mail collectors only)
- `public/api/cockpit.php`, `public/cockpit/js/`, `public/cockpit/cockpit.css`
- `config/collectors.php`
- `bin/collect-facts.php` (register the Calendar and Mail collectors beside the others)
- `tests/CockpitDayTest.php` (new), `tests/fixtures/cockpit-day/` (new: a fake .ics, fake IMAP transcript)
- `.claude/work/109-cockpit-day/progress.md`, `result.md`

## Done-test
`c:\xampp\php\php.exe tests/run.php --filter CockpitDay` passes: against a
fixture .ics and a scripted fake IMAP server (no real network), the snapshot
returns the month's events with recurring ones expanded, today's agenda, and
email rows with summaries from a stubbed model; the IMAP client sends only
LOGIN, EXAMINE, UID SEARCH, UID FETCH (BODY.PEEK, ENVELOPE) and LOGOUT; no
source file under `src/Mail/` contains STORE, COPY, MOVE, EXPUNGE, APPEND or
SELECT; the app password never appears in the log or the API output; a
follow-up write without the token is refused.

Then the mutation check (break the command allowlist, then the redaction),
name the failing tests, restore, green. Filtered runs only.

## Boundaries
No commit, no push, nothing outward beyond the IMAP read and the iCal fetch at
run time. No real network in tests. Nothing installed. Never list applies.

## Progress
Keep `progress.md` beside this file, one line per step. Already there? Resume
from it after checking the disk.

## Report
`result.md` under 200 words: files, tests, mutation result, which iCal rules
are not expanded. Say what was not done.
