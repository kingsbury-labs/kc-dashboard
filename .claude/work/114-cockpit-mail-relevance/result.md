# 114 result

Files: src/Mail/Relevance.php (new: tag, prompt, corrections), src/Mail/Summarizer.php (shared `generate` loopback call), src/Mail/ImapReader.php (sender `addr`), src/Collectors/Mail.php (tags each message, summarises tagged mail only, optional `$tag`), src/Cockpit/CockpitDay.php (filters rows, `{sender, project}` POST behind checkWrite), CockpitDeps.php, public/api/cockpit.php, js/data.js (`postSender`), js/views.js ("Not mine" / "Project..." buttons, project tag), cockpit.css, config/collectors.php (vendors, kc-care path, senders file).

Tests: tests/CockpitMailRelevanceTest.php (3), fixture tests/fixtures/cockpit-mail/imap.json; CockpitDayTest helpers take a tag and fixture dir. Green: CockpitMail 3/3, CockpitDay 6/6.

Mutation: skipping the domain match failed "a client-domain sender is tagged without a model call..." (model asked 3 times). Ignoring corrections failed "a correction marking a sender not mine...". Restored, green.

Calls: fixture domains are example.com/net/org (PII hook). Old cache rows have no sender address, so they are dropped and fetched again on the next run.

Current inbox: the 10 cached rows predate tagging and carry no sender address, so I cannot say how many would show until the collector runs once. No live Gmail, Ollama or browser check.
