# kc-finance integration (from the kc-finance session, 2026-10-17)

Input for the card 60-69 re-plan. kc-finance lives at `C:\Users\roban\kc-finance`
(Node 24 scripts; Bigcapital ledger in Docker on 127.0.0.1:8000).

## Contract

The dashboard reads one file and nothing else:
`C:\Users\roban\kc-finance\alerts\alerts.json`, written atomically (tmp then
rename) by kc-finance's scheduled runs. Schema pinned in kc-finance as
`config/alerts.schema.json`. The combined writer is kc-finance's next card;
today only the separate files exist (backup.json, balance.json,
payments-review.json, reminders-due.json). Build against the contract below.

```json
{"generated_at": "ISO", "schema": 1,
 "sources": [{"name": "", "last_ok_at": "", "status": ""}],
 "facts": [{"id": "invoice:INV-0042:overdue-14", "kind": "", "subject": "",
            "due_date": "YYYY-MM-DD|null", "severity": "low|med|high",
            "amount_cents": 0, "evidence": "short text",
            "url": "http://127.0.0.1:8000/...|null"}]}
```

- `generated_at` older than 26h means the finance source is stale (coverage health).
- Fact ids are stable across runs; key shown/snoozed/acted state on them.
- Render a `url` only if it starts with `http://127.0.0.1:8000/`.
- `subject` carries the Bigcapital customer display name. If it differs from
  `kingsburycreative/clients/_DASHBOARD.md`, keep an alias map on our side.
- Rob's finance to-dos arrive as `kind: "todo"` facts. No separate tracking.

Facts kc-finance will send: invoice overdue 7/14/30 days, invoice due within 5
days, bill due within 5 days, payment needs review, reminders awaiting approval,
uncategorized bank lines, monthly balance check fail, no TD statement by the
7th, missing receipt over $75 after 14 days, backup or restore-verify fail or
last good backup over 48h, payments-run step failed, money to move to the tax
reserve, tax dates at 30/7/1 days out. Display-only numbers (not attention):
open receivables, projected year-end tax, TD balance at last statement, tax
reserve vs projected tax.

## Never in the dashboard's DB or page

Credentials or .env values, bank or card account numbers, raw bank CSV lines or
transaction histories, receipt files or contents, Rob's salary or personal
income. Client names, invoice numbers and amounts are fine.

## Read-only

The dashboard never writes to kc-finance files or Bigcapital, and never reads,
flags or moves mail in kc-finance's billing mailbox. Actions go through the
ask-Claude box running `claude -p` with cwd `C:\Users\roban\kc-finance`. A later
one-click approval would be an approval file kc-finance reads, never a direct call.

## Ask-Claude box, for kc-finance

- kc-finance is outside htdocs, and ProcessRunner only allows direct children
  of one root. Plan: a config list of exact extra folders, each still checked
  by SafeDir. Not a second root.
- kc-finance's credential hook refuses its scripts while
  `kc-finance\scripts\` has uncommitted changes (its loop mid-card). Show
  "finance busy, try later", not an error.
- Under Git Bash, `/api/...` args get path-converted. The box's prompt text
  sets `MSYS_NO_PATHCONV=1` or uses PowerShell.

## Reserved

Ports 8000 and 8001 (Bigcapital real and trial). Scheduled tasks named
"KC Finance ..." (ours are under `\KC Dashboard\`). Don't start this loop while
`C:\Users\roban\kc-finance\.claude\work\loop\lock` exists with no STOP beside it.
