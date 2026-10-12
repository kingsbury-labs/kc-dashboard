
## 2026-10-12 card 05, Logger with redaction: parked
Worker wrote src/Log.php and tests/LogTest.php; the filtered tests pass. The mutation check (break redact(), rerun --filter Log) was refused by the permission classifier as a "security test removal", so the card could not reach the gate. Not routed around. Code is on branch wip/05-log. Needs Rob: allow that mutation run (or run it himself), then gate the card from wip/05-log.
