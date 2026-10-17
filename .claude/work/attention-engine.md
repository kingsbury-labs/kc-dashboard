# Attention engine: handoff from the 2026-10-12/13 session

Written for the next session in this folder (and Rob). Outcome of an evening of benchmarks, research and two reviews (Codex, Fable). Nothing here is built yet. Read this, then `reviews/attention-fable.md` and `reviews/attention-codex.md`.

## What Rob wants

A short "needs attention" list on the new tab: things across his business that need him, each with a verb, a date, one line of why and a link to its source. His examples: a key or cert near end of life, someone owing him something by tomorrow, a security update recommended for a project, a news story that affects a project. He does not know the full list of alert types; the system should discover them from his feedback.

Decided by Rob this session:
- Mail is out of scope. Drop the mail digest (cards 65, 66 and the mail parts of 08, 61, 68). His desktop already notifies him.
- The Ask box becomes a plain `claude -p --model sonnet` chat. No local model answers questions.
- Bonsai lives system-wide, not inside this project. Planned home: `D:\Dev\llm` (fork + models side by side). Not moved yet; it is still in `llm/` here. Do not put it in Ollama's model folder: Ollama stores hashed blobs and prunes unknown files on start.

## Evidence (measured, harness in `llm/bench/`, git-ignored)

Same 696 labelled rows (path references after the C: to D: move, classify FIX / BROKEN / CLEAR_CACHE / IGNORE), same prompt, batches of 25. Scored with `node llm/bench/rescore.cjs`.

| model | right | wrong | real fixes called "fine" | time | list price |
|---|---|---|---|---|---|
| Opus, low effort, claude -p | 97.7% | 2.3% | 9 | 9.5 min | $7.45 |
| Sonnet, low effort, claude -p | 94.0% | 6.0% | 35 | 8.6 min | $3.97 |
| Haiku, claude -p | 86.6% | 13.4% | 35 | 27.5 min | $2.28 |
| Bonsai | 81.6% | 18.4% | 93 | 6.0 min | $0 |
| gpt-oss:20b | 65.7% | 34.3% | 66 | 12.4 min | $0 |

- Bonsai's mistakes are one-directional: confident "this is fine" on real problems. When it acted, it was right (FIX precision 95%, every path exact).
- Offering UNSURE barely changes behaviour (Bonsai used it on 1.7% of rows, gpt-oss once).
- A logprob confidence gate failed in batched mode (at 0.90, 40% of rows escalate and 36 misses still pass). One research run at one row per call with a schema-forced label looked better (150 rows); unreplicated.
- gpt-oss:20b runs 57% on CPU, is slower than Claude and wrong a third of the time, including 78 "fixes" to history files. Rejected as the fallback.
- `claude -p` reported about $0.06 per Haiku batch, roughly 30x what API pricing predicts. Likely Claude Code's own per-call overhead; unconfirmed (cache token counts were not logged).
- llama-server with default 4 slots corrupted under two concurrent requests (outputs `/////` forever). It must run with `-np 1`, and a canary prompt must run before each batch.

## Credits and the API rule

Rob's Max 5x plan gives $100/month of API credit on his linked Console org (next grant 2026-10-22, no rollover). Anthropic's email: credits apply to "the Claude API, Claude Managed Agents, the Claude Agent SDK, and the Playground only. They can't be used for Claude Code sessions." So `claude -p` never spends them. When credits run out, requests stop; the card is only charged if Console auto-reload is turned on (keep it off).

Rob's standing rule forbids direct Anthropic API use. It stays forbidden until he lifts it. Both reviewers argue scheduled jobs belong on the API (structured output, Batch at 50% off, no drain on his coding limits). That is his call, open.

## The design both reviewers converged on

1. **Facts, not alerts.** A `facts` table: id, source, kind, subject, salient hash, due date, severity, url, collected_at, state (new, shown, held, snoozed, dismissed, acted). Append-only observations with provenance (Codex: also keep the exact evidence span and parser version).
2. **Code owns anything with a date or a CVE, and always shows it.** Collectors: SSL expiry, endoflife.date, OSV-Scanner over lockfiles (rank by installed version, prod vs dev, fix available, CISA KEV), due dates from `kingsburycreative/clients/_DASHBOARD.md` and `_PIPELINE.md`.
3. **Asymmetric model rule.** Models may add or rank candidates. A model "no" may never delete an item or be the only reason it is hidden. Held items go to a visible "also seen" fold.
4. **Display budget.** Top five max. Each item: verb, date, evidence, source, snooze. Re-show only when its state changes (30, 7, 1 days out; severity up).
5. **Daily read, incremental.** New facts plus running state by id, never the whole table. Code validates every id and URL the model returns; never render a model-written URL.
6. **Feedback with reasons**, not just useful/not: useful, already knew, not actionable, too early, irrelevant, wrong evidence, resolved. No click is never a negative. Implicit signals (commits, payments) only count as evidence of a miss.
7. **Rule promotion.** A pattern useful three times: Claude drafts a predicate, it is replayed on history, Rob approves, it then runs free and stops reaching any model.
8. **Coverage health** on the page: last successful run per source, failures, stale collectors.

Dropped after review: Bonsai summarising news, ai-ta style baselines, a 90-day backfill, A/B prompt variants, any single daily call that decides everything.

Where the reviewers differ: Codex wants per-item Haiku calls on the API and Sonnet only for ambiguous items; Fable wants one daily Sonnet call through `claude -p` (`--system-prompt`, `--setting-sources ""`, `--tools ""`, read `usage`). Coordinator's recommendation: start on `claude -p`, measure its overhead from `usage`, move to the API only if Rob lifts the rule.

## Spec and plan changes this implies

- Remove mail (cards 65, 66, mail credentials in 08, mail tasks in 68).
- Remove gpt-oss as fallback. When Bonsai is down, jobs wait.
- llama-server flags gain `-np 1`; canary before every batch; load on demand and unload after, not at logon (card 68 currently pins the model all day).
- Card 64 impact tags: Bonsai may only add story-to-project candidates, never be the matcher of record.
- Feature 6 numbers, extras and the health strip stay as they are.
- Paths: every `c:\xampp\htdocs` in SPEC.md, plan.md and context.md now means `D:\Dev\htdocs`. XAMPP itself (`C:\xampp`, php.exe, httpd) did not move. The vhost file `ops/apache-kc-dashboard.conf` was rewritten to D; Apache needs a restart from the control panel to pick it up.

## First things to build

1. The `facts` table and the rule collectors, rendered straight to the page, no model in the path. Reviewer says these fire on day one: `mail.sembr.co` cert expired 2026-10-03; PHP 8.2 security support ends 2026-12-31 and XAMPP runs 8.2.12; a Roanoke follow-up overdue since June. Not yet checked.
2. The daily read: one call, JSON schema, id validation, top-five cap, "also seen" fold, feedback buttons writing labels with the prompt hash.
3. Nothing else until a week of labels exists.

## Open for Rob

- Lift the no-API rule for this project's scheduled jobs, or not.
- Whether Bonsai has any job here at all (both reviewers: not on the critical path).
- Approve the spec and plan changes above before the loop re-plans.
