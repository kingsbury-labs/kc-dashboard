# 72. Daily read: result

Files: `src/Attention/DailyRead.php`, `bin/daily-read.php` (CLI guard, `--force`, once a day via `due()`), `src/migrations/005_reads.sql`, `tests/DailyReadTest.php`.

Tests added: 7 in DailyReadTest. DailyRead 7/7, BinCli 2/2, full suite 278/278.

Mutations, each restored, then green:
- Sent-id check off: failed "an invented id in the reply is dropped...".
- Reason URL scrub off: failed "a URL in a reason is not stored as a url...".
- Incremental check off (send everything): failed "only facts newer than the last read..." and "invalid JSON leaves the previous ranking...".
- `annotate()` drops unranked facts: failed "a fact the reply omits or ranks last is still returned by Selector".
None refused.

Calls made:
- "Newer than the last read" is a fingerprint (salient hash, severity, due date) against the last good read, not `collected_at`: collectors re-collect every 30 minutes, so a timestamp would resend the whole table daily.
- A failed or invalid read is stored with a NULL ranking; the previous ranking stands and its facts go again next time.
- Reply shape `{"items": [{id, rank, reason}]}`, since StructuredOutput refuses a top-level list. Ranks renumbered 1..n.
- The read never sets `shown`/`held`; it only annotates.

Not done: not scheduled.

## Round two

Files added: `src/Claude/ClaudeRunner.php`, `tests/ClaudeRunnerTest.php`, `src/Attention/Selector.php`, `tests/SelectorTest.php`.

- A reason with any link shape is now dropped whole (null) and the fact keeps its rank. The shapes: colon followed by a non-space, `//`, a backslash, `www.`, a host name. Each shape from the audit is tested, plus plain reasons that survive.
- Selector uses rank only after severity and due date.
- `--no-session-persistence` added to UNTRUSTED and asserted. I did not run claude to confirm the flag; I took 2.1.303 on your word.
- Data block: kind and subject capped at 200 chars, evidence at 500, 64 KB in total. Facts go in most-pressing first. A new fact over the cap stays new for the next read and a running one keeps its fingerprint.

Mutations, each restored: rank before severity failed "a reply that ranks a low fact first..."; backslash check off failed "every link shape..."; byte cap off and over-cap facts marked known each failed "the data block is capped..."; flag removed failed ClaudeRunner "a Prompt runs in a fresh empty temp folder...". None refused.

Full suite 282/282.
