# Proposal: the "needs attention" engine for KC Dashboard

For review. Tear it apart, then say what the best design is.

## The end result

Rob opens a Chrome new tab and sees a short list of things that need his attention across his business, each with one line of why and a link to its source. Examples he gave: "a key/cert is about to reach end of life", "this person owes you X by tomorrow", "a security update is recommended for project Y", a news story that affects one of his projects, an accounting item. He does not know the full list of alert types in advance and wants the system to discover them. Mail is out of scope (his desktop already notifies him). A free-form question box is replaced by a plain `claude -p --model sonnet` chat.

## Who and what

Rob: sole-proprietor web developer and teacher (Kingsbury Creative, Ontario), ~60 projects in `D:\Dev\htdocs` (Laravel, WordPress, vanilla PHP, Node). Project: `D:\Dev\htdocs\_kc-dashboard` (read `docs/SPEC.md`, `.claude/work/plan.md`, `.claude/context.md`; paths in them still say `C:\xampp\htdocs`, the folders moved to `D:\Dev\htdocs` on 2026-10-12). Stack: vanilla PHP 8.2 on XAMPP, Windows 11, SQLite, no Composer, no build step, Task Scheduler for jobs. GPU: RTX 5060, 8 GB.

Models available:
- Local: Ternary-Bonsai-2-27B PTQ1_0 (PrismML, ternary quantized from Qwen3.8-27B, 5.9 GB) on PrismML's llama.cpp fork `llama-server`, OpenAI-style API, 8192 context, json_schema output, logprobs available, about 46 tok/s, thinking off.
- `claude -p` CLI on Rob's Max 5x subscription (shared usage limits with his coding work).
- Anthropic API: a linked Console org gets $100/month in credits (no rollover; requests stop when spent unless auto-reload is on, which stays off). Rob has historically forbidden direct API use; treat it as available only if the design clearly benefits, and say so.
- gpt-oss:20b on Ollama: tested and rejected (see below).

## Evidence measured on 2026-10-12

Same 696 labelled rows (classify file path references after a drive migration: FIX / BROKEN / CLEAR_CACHE / IGNORE), same prompt, batches of 25:

| model | right | wrong | real fixes called "fine" | time |
|---|---|---|---|---|
| Claude Opus, low effort, claude -p | 97.7% | 2.3% | 9 | 9.5 min |
| Bonsai | 81.6% | 18.4% | 93 | 6.0 min |
| gpt-oss:20b, low reasoning | 65.7% | 34.3% | 66 | 12.4 min |

- Bonsai never wrote a wrong path when it acted (FIX precision 95%, all FIX paths exact). Its failure is one-directional: confident "this is fine" on real problems.
- Adding an UNSURE option: Bonsai used it on 1.7% of rows and accuracy fell to 78.2%. gpt-oss used it once.
- Logprob confidence gate, batched mode: at a 0.90 cut, 40.5% of rows escalate and 36 missed fixes still pass. Fails.
- Logprob gate, one row per call with schema-forced label (150 rows, research agent): all 13 wrong "fine" calls had confidence 0.49 to 0.89, correct ones median 0.97. Promising, small, unreplicated.
- llama-server with default 4 slots corrupted under two concurrent requests (outputs `/////` forever). Must run `-np 1` with a canary check.
- gpt-oss runs 57% on CPU, slower than Claude and wrong a third of the time, including 78 "fixes" to history files.

## Proposed design (current draft)

1. **Facts, not alerts.** Every source becomes facts in SQLite with provenance (source file or URL, collected_at) and confidence. Sources: SSL certs, token/key expiry dates, endoflife.date for PHP/Laravel/WordPress/Node versions, OSV-Scanner over each project's lockfiles, client tables in `kingsburycreative/clients/_DASHBOARD.md` and `_PIPELINE.md`, news feeds, project CLAUDE.md files, git activity.
2. **Code rules first.** Expiry, versions, CVEs, due dates in tables: deterministic rules, exact, free.
3. **Bonsai shrinks text.** For unstructured text only (news, notes, changelogs), Bonsai writes a one-line summary or extracts {who, what, amount, date}. A code check pulls dates, amounts, versions and names from the original with regex; if the summary drops any, the original goes onward whole. Bonsai never decides "fine".
4. **Claude decides attention once a day.** One call reads the day's facts and summaries and writes the short attention list, each item with its source. Via `claude -p` or the API Batch endpoint.
5. **Learning loop.** Rob marks each flag useful / not. Implicit labels too: a commit to that project or a paid invoice within N days counts as useful. Weekly, Claude proposes promoting repeated useful flags into code rules; Rob approves. Store the learned weights crob-style (D:\Dev\htdocs\crob: facts with provenance and confidence, interests derived from behaviour).
6. **Baselines.** Borrowed from `D:\Dev\htdocs\ai-ta\lib\FingerprintGenerator.php`: a running baseline per project (error counts, traffic, dependency churn) with sample count and confidence; deviation creates a fact.
7. **Kickstart.** Backfill the last 90 days in one batch so Rob labels ~200 flags in one sitting instead of waiting weeks.
8. **Grading.** Code check on every Bonsai summary; Claude samples 5% daily against originals; disagreements become prompt examples. Fixed regression set rerun whenever model, fork or prompt changes. Prompts versioned with A/B variants (borrowed from ai-ta `lib/PromptTemplate.php`, which tracks usage only, not outcomes).

My guesses at success: code rules ~certain, Bonsai shrinking ~80%, Claude daily read ~60% (risk: noise kills Rob's clicking), overall ~60%.

## What I want from you

Research is allowed and expected. Cite sources for factual claims; say "unverified" where you have none.

1. Where is this design wrong, wasteful or fragile? Be specific.
2. Token use: what is the cheapest design that keeps correctness? Consider prompt caching, batching, Batch API pricing, what Bonsai should and should not touch, how much context Claude really needs daily, whether the daily read should be incremental (only new facts plus a running state) rather than full.
3. Correctness: how to stop the "this is fine" failure end to end, how to stop alert noise, how to make missed items visible.
4. The learning loop: is click + implicit + rule-promotion the right shape? Better prior art (recommender feedback, active learning, anomaly detection, alert fatigue research from SRE/on-call)?
5. Is Bonsai worth keeping at all here versus Claude Haiku on the API credits, given 8 GB VRAM and one-at-a-time serving? Give numbers.
6. End with your recommended design: specific components, which model does what, data flow, token budget per day, and the first three things to build. Keep it tight. Rob reads short answers.

Constraints: read-only on every file. Do not edit, write, commit or start servers in Rob's projects.
