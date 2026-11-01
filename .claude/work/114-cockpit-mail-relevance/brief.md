# 114. Cockpit email: only mail about Rob's projects (Risky)

## Objective
Email summaries and follow-ups show only mail that concerns Rob's projects,
clients and the vendors those projects run on, each tagged with its project.
Everything else is hidden. Rob can correct a tag with one click and the
correction sticks for that sender.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md` (mail is read-only)
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 114
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\109-cockpit-day\result.md`
- `D:\Dev\htdocs\_kc-dashboard\src\Mail\Summarizer.php`, `src\Collectors\Mail.php`, `src\Cockpit\CockpitDay.php`, `public\api\cockpit.php`, `public\cockpit\js\views.js`
- `D:\Dev\htdocs\_kc-dashboard\config\clients.php`, `config\projects.php`

## Context
- Rob, 2026-11-01: "I really only need emails associated with my projects. The
  AI needs to be smart enough to know." Seen live: Airbnb and Klarna promos sit
  beside a DigitalOcean billing hold that matters.
- Entity list, built on the collector run, no new database: project names from
  `config/projects.php` and the project folders the dashboard already lists;
  clients and their domains from `config/clients.php`,
  `D:\Dev\htdocs\kc-care\clients.json` (key and `domain`) and the folder names
  under `D:\Dev\htdocs\kingsburycreative\clients\`; vendors in a small config
  list (DigitalOcean, WHC, Helcim, Stripe, GitHub, Cloudflare, Google Workspace
  as a start). Read-only, names and domains only.
- Order: 1) sender address or domain matches an entity or a learned sender:
  tag it, no model call; 2) else the local model gets the entity names plus
  sender, subject and the first 1,000 characters and answers JSON
  `{"project": "<name>"|null}`; null is hidden. 3) Rob's corrections win over both.
- Corrections: each email row gets two text buttons, "Not mine" and
  "Project...", a token-guarded POST (checkWrite) that stores sender to
  project or sender to ignore in `data/mail-senders.json`. No mail text stored.
- Follow-ups only from tagged mail.

## Files you may touch
- `src/Mail/`, `src/Collectors/Mail.php`, `src/Cockpit/`
- `public/api/cockpit.php`, `public/cockpit/js/`, `public/cockpit/cockpit.css`
- `config/collectors.php`
- `tests/CockpitMailRelevanceTest.php` (new), `tests/fixtures/cockpit-mail/` (new), `tests/CockpitDayTest.php`
- `.claude/work/114-cockpit-mail-relevance/progress.md`, `result.md`

## Done-test
From plan.md card 114, word for word:

`c:\xampp\php\php.exe tests/run.php --filter CockpitMail` passes: against
fixture mail and a stubbed model, a sender on a client domain is tagged without
a model call; a promo the model calls null is hidden; a correction marking a
sender "not mine" hides that sender's later mail and a correction to a project
tags it; a correction POST without the token is refused; no mail body text is
written to disk.

Then the mutation check (skip the domain match, then ignore corrections), name
the failing tests, restore, green. Filtered runs only.

## Boundaries
No commit, no push. IMAP stays EXAMINE and BODY.PEEK only. Never list applies.

## Progress
Keep `progress.md` beside this file, one line per step. Resume from it if present.

## Report
`result.md` under 200 words: files, tests, mutation result, how many of the
current inbox rows would show. Say what was not done.
