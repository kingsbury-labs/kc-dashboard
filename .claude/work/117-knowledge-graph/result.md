# 117 result

Files: src/migrations/010_graph.sql; src/Graph/GraphStore.php, Readers.php, Classifier.php (new); src/Collectors/Graph.php (new, runs before Mail); src/Collectors/Mail.php (Classifier by default, rows carry reason/unsure); src/Mail/ImapReader.php (EXAMINE Sent Mail, envelope-only FETCH added to the allow list); src/Cockpit/CockpitDay.php (reason, Unsure pile); public/cockpit/js/views.js, cockpit.css (chip hover reason, Unsure (n)); bin/collect-facts.php.

Tests: tests/GraphTest.php (4), fixtures tests/fixtures/graph/. .env, .credentials and .git/config are written at test time into a temp copy, never committed. Graph 4/4, CockpitMail 3/3.

Mutation: skipping the walk failed 3 Graph tests (contact walk, no-path/Unsure, correction wins). Adding .env to the reader's file list failed "no .env value ... reaches the graph". Restored, green.

Calls: walk follows edges weight >= 0.5 and never passes through a project or vendor; a correction's confirmed path beats other paths; "not mine" is no edge, read from the senders file. Corrections still POST to mail-senders.json and become edges on the next Graph run.

Not done: Finance and Calendar readers (calendar cache stores no attendees); the project-screen Graph view; file-mtime skipping (only sent mail is incremental). No real collector run, so no live counts.
