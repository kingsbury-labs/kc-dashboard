# 133 result

Files: new `src/Mail/MailRules.php` (rules 1-6, NEVER and SYSTEM_LOCAL constants, client terms from PROJECT.md and site links, _PIPELINE.md names). Also ClientContacts (adds `person`), Relevance (byContact goes through rules 1-2), Classifier (MailRules::place before the graph walk), Collectors/Mail (rows get `rule` and `kind`; rows without a `rule` key are read again), CockpitDay, views.js (Unsorted moved above the rows, an "About X" label), cockpit.css, config/collectors.php (`studio` domains). Fixtures are in `tests/fixtures/cockpit-mail/clients/` and `thread/`.

Tests: 10 new in CockpitMailRelevanceTest. Changed expectations: the CockpitDayTest command list, the contacts parse output (now has person), Ownco billing@ (now acme), and the GraphTest sent-rerun check, now limited to the graph's own rerun. MailRelevance, Cockpit and Graph all pass.

Mutations, each caught:
- To/Cc removed: failed rule 1, rule 2, priority.
- System-sender exclusion removed: failed rule 2, free-mail client.
- Thread claim off: failed rule 3, priority, collector.
- about changed to from: failed rule 4, priority.

Collector for threads: an envelope fetch for To/Cc, then EXAMINE Sent Mail and the envelopes of its last 200 messages. Threads are joined by normalised subject, since ImapReader is outside this card and keeps no thread id. Everything stays read-only.

Not done: the live capture shows the layout only. At 1080x1920 "Unsorted (1)" sits at 738px in a card ending at 1026px, scrollTop 0. Mail sorting never took effect live. Two collect-facts runs left `data/mail-cache.json` without `rule` keys, so something else writes that cache, maybe a scheduled worker running old code. No PNGs were saved here.
