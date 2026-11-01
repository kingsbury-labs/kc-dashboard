# 117. Project knowledge graph: one map of projects, people, clients and vendors (Risky)

## Objective
The dashboard keeps a project knowledge graph in SQLite, rebuilt incrementally
on the collector run: who and what belongs to which project. Email
classification walks the graph first and only asks the local model, with that
project's neighbourhood as context, when the walk finds nothing. The cockpit
shows each email's project with the reason, and an "Unsure" pile Rob can tag in
one click; every tag becomes an edge.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md` (mail read-only, Never list)
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 117
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\114-cockpit-mail-relevance\result.md` and `src\Mail\Relevance.php` (the flat version this replaces)
- `D:\Dev\htdocs\_kc-dashboard\src\Db.php`, `src\migrations\` (how tables are added), `src\Mail\`, `src\Collectors\Mail.php`, `src\Collectors\Calendar.php`, `src\Cockpit\CockpitDay.php`, `public\cockpit\js\views.js`

## Context
- Rob, 2026-11-01: "The AI doesn't seem to know what in the email is related
  to any given project. We need something that ties everything together so the
  AI can make accurate calls." The flat name list from 114 is the defect.
- Approach decided by research (`D:\Dev\htdocs\_kc-dashboard\.claude\work\agentos\kg-research.md`,
  read it): plain SQLite `node`, `edge` and `alias` tables plus a correction
  log in the dashboard's existing database, walked with recursive CTEs; no
  graph DB, no Python, no LLM extraction. Entity resolution is deterministic:
  exact normalised email address first, then explicit same-person edges across
  addresses; never auto-merge on a name alone. Every edge has `confirmed`
  (Rob's click sets it) and a weight; the model's fallback answer is stored as
  an unconfirmed low-weight edge. A tie in the walk goes to the fallback too.
- Nodes (one table, typed): project, client, person (keyed by email address),
  domain, vendor, repo, topic. Edges (one table, typed, with source and
  weight): person works_for client, client owns project, project has_domain,
  project uses vendor, project has_repo, thread about project, person
  emailed_about project, topic of project. Each edge records where it came
  from so a rebuild can replace one source's edges without touching others.
- Sources, each a small reader, read-only:
  1. Project folders the dashboard already lists (config/projects.php):
     names; domains and service names found in CLAUDE.md, README.md,
     .claude/context.md, .claude/project.md, docs/*.md, .env.example (keys and
     hostnames only, never values; never read .env or anything under
     .credentials); git remote URL for the repo.
  2. `D:\Dev\htdocs\kingsburycreative\clients\` client records (folder names,
     contact emails and domains in their markdown).
  3. `D:\Dev\htdocs\kc-care\clients.json` (client key, domain).
  4. kc-finance's alerts file the Finance collector already reads (who pays
     for what, by client name).
  5. Calendar events the Calendar collector already caches: attendee emails
     linked to the project the event title names.
  6. Sent mail: over the existing read-only IMAP client, EXAMINE "[Gmail]/Sent
     Mail", envelopes only (to, subject, date) for the last 90 days; a
     recipient plus a subject that names a project becomes a person
     emailed_about project edge. No bodies stored.
  7. Rob's corrections from 114 (`data/mail-senders.json`), migrated into edges.
- Classification order for an inbox message: (a) sender person node with a
  path to exactly one project within 3 hops: tag it, reason = the path in words
  ("Maya Henike works for Henike Contracting, which owns henike-contracting");
  (b) else the local model gets the top candidate projects with their
  neighbourhood (client, people, domains, vendors, topics, newest 5 thread
  subjects) and answers JSON {project|null, confidence 0-1, reason}; (c) below
  0.6 it goes to "Unsure". Vendors alone (a WHC invoice) resolve through
  project uses vendor; one vendor used by many projects shows as "Hosting,
  several projects" not a guess.
- Cockpit: each email row shows its project chip and the reason on hover;
  "Unsure (n)" pile under the list; "Not mine" and "Project..." stay and write
  edges. A small Graph view under the project screen lists that project's
  people, domains, vendors and topics (text lists, no drawing).
- Size: Rob's mail is small; first build may take a minute, later runs only
  read what changed (store last UID per mailbox and file mtimes).

## Files you may touch
- `src/Graph/` (new: GraphStore.php, readers, Classifier.php)
- `src/migrations/` (new migration file only)
- `src/Mail/`, `src/Collectors/Mail.php`, `src/Collectors/` (a new Graph collector)
- `src/Cockpit/`, `public/api/cockpit.php`, `public/cockpit/js/`, `public/cockpit/cockpit.css`
- `bin/collect-facts.php`, `config/collectors.php`
- `tests/GraphTest.php` (new), `tests/fixtures/graph/` (new), `tests/CockpitMailRelevanceTest.php`
- `.claude/work/117-knowledge-graph/progress.md`, `result.md`

## Done-test
From plan.md card 117, word for word:

`c:\xampp\php\php.exe tests/run.php --filter Graph` and `--filter CockpitMail`
pass: against fixture project folders, client records, a fake IMAP server (inbox
and sent envelopes) and a stubbed model, the graph links a contact to a client
and its project; an inbox message from that contact is tagged by graph walk
with a reason naming the path and no model call; a message with no path goes to
the model with that project's neighbourhood in the prompt; a low-confidence
answer lands in Unsure; a correction becomes an edge that wins next run; no
.env value, credential file or mail body is read into the graph or the API
output; the IMAP client still sends only the allowed read commands.

Then the mutation check (skip the graph walk, then let a reader open .env),
name the failing tests, restore, green. Filtered runs only.

## Boundaries
No commit, no push. Read-only against every source. Never list applies.

## Progress
Keep `progress.md` beside this file, one line per step. Resume from it if present.

## Report
`result.md` under 200 words: files, tests, mutation result, node and edge
counts after one real collector run if it ran. Say what was not done.
