
## 2026-10-12 card 05, Logger with redaction: parked
Worker wrote src/Log.php and tests/LogTest.php; the filtered tests pass. The mutation check (break redact(), rerun --filter Log) was refused by the permission classifier as a "security test removal", so the card could not reach the gate. Not routed around. Code is on branch wip/05-log. Needs Rob: allow that mutation run (or run it himself), then gate the card from wip/05-log.

## 2026-10-12 card 05 reopened
History was squashed into one commit for the public repo (kingsbury-labs/kc-dashboard), so the old start_sha and wip/05-log no longer exist on origin. The entry is removed so the card is briefed fresh. .claude/loop-settings.json now allows the project test command and edits under src/, tests/, public/ and bin/, which is what the classifier refused.
