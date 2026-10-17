# Attention engine review (Fable), 2026-10-13

Review of `attention-proposal.md`. Read-only; nothing was edited or started. Numbers marked as estimates are the reviewer's, not measured.

## 1. Where the design is wrong, wasteful or fragile

Eight components before one alert ships. Items 1 to 4 of the proposal are the product; 5 to 8 are instrumentation for a product that does not exist yet, and three do not survive contact with the data:

- Baselines (item 6). `ai-ta/lib/FingerprintGenerator.php` is a student code-style fingerprint for plagiarism detection. Only `sample_count` plus `confidence = min(1, n * 0.2)` transfers. Error and traffic baselines across 60 mostly dormant projects will fire on "traffic down 40%" for a site with ten visits a day. Defer until Bugsink or GA data flows and Rob asks.
- Crob-style weights (item 5). Crob's confidence vectors corroborate one fact across web domains. The attention problem is per-rule precision: a counter with decay, not a provenance graph. Borrow crob's 14-day half-life only.
- A/B prompts (item 8). One user and about five flags a day gives no statistical power. Keep prompt versioning (store the prompt hash with every decision), drop variants.
- Kickstart (item 7). RSS feeds hold 10 to 50 items, not 90 days. Only deterministic sources can be backfilled, and they need no labels.

Bonsai "shrinks text" (item 3) does nothing useful. RSS entries are already a headline plus a short description, and the name/date/amount check would pass the original through on nearly every news item. Cut the summarizer.

The spec's current use of Bonsai is the dangerous one: Feature 5 and card 64 have Bonsai deciding which stories match which project. A model that fails by dropping things must never sit where dropping is the output. Flip it: the cheap model only widens the candidate set, Claude only narrows it.

Operational fragility:
- `-np 1` plus canary: the canary runs before every batch, not once at boot. The `/////` corruption is per-process state.
- `bin/model.php ensure bonsai` at logon (card 68) pins 7.6 of 8.1 GB VRAM all day for a 15-minute job. Load on demand, unload after. Rob games on this GPU.
- Logprob gate: llama.cpp `n_probs` are raw-logit softmax by default; `post_sampling_probs: true` renormalises under a json_schema grammar (https://github.com/ggml-org/llama.cpp/blob/master/tools/server/README.md). Pin the setting in `config/llm.php` and the regression set or any threshold stops meaning anything.
- `claude -p` without `--bare` loads Rob's `~/.claude` context (global CLAUDE.md, persona files, SessionStart hook): thousands of tokens per run and a correctness hazard for a flat classifier. `--bare` needs an API key, so use `--system-prompt` plus `--setting-sources ""` and `--tools ""`, and read `usage` in the JSON output to confirm input size. Unverified until measured.
- Hallucinated links: output references facts by id; code drops any item whose id or URL was not in the input. Never render a model-written URL.

## 2. Token use

Prices verified 2026-10-13 at https://platform.claude.com/docs/en/about-claude/pricing: Haiku 5.5 $0.10 in / $0.50 out per MTok (under 100k prompt), Sonnet 5.5 $2 / $10, Opus 5.5 $4 / $20. Batch API halves these (https://platform.claude.com/docs/en/build-with-claude/batch-processing). Cache reads 0.1x base; minimum cacheable prompt 512 tokens on 5.5 models.

Credits (from Anthropic's email to Rob): the $100/month applies to the API, Managed Agents, Agent SDK and Playground only, never Claude Code sessions. Every `claude -p` call draws on the Max 5x limits.

Daily volume estimate (news volume unverified; assumes 300 items/day, about 150 stories after clustering): instructions 800, project profile 1,800, running state 1,200, new rule facts up to 1,500, story candidates 4,000. About 9,300 input and 1,500 output. On the API: Sonnet about $0.034/day, Opus about $0.067/day, a Haiku recall pass about $0.003/day. Everything under $3/month.

Incremental, not full: the daily read gets new facts plus running state, keyed by fact id. Code refuses to re-show a dismissed id unless a hash of its salient fields changed.

## 3. Correctness

- Rule facts with a date or CVE never pass through a model's judgement. Code shows them; a model may rank, not drop.
- The cheap model is add-only. Only Claude may hold an item back, and "held" is a state, not deletion.
- Held items render in a collapsed "also seen" fold with a count. One click promotes an item, and that click is the label "missed".
- Uncertainty strip: items in the uncertain confidence band go to Rob as yes/no questions, capped at five a day (uncertainty sampling, Settles, https://research.cs.wisc.edu/techreports/2009/TR1648.pdf).
- Weekly miss audit: Rob's actual week (git log, paid invoices) against what was flagged. An action with no preceding flag is a miss.

Noise: every page actionable (https://sre.google/sre-book/monitoring-distributed-systems/). Every top item carries a verb and a date or goes below the fold. Hard cap of five. Re-surface only on state change (30, 7, 1 days out; severity up). Dismiss with a one-word reason. Per-source and per-rule shown/clicked/dismissed counters with a 14-day half-life; a source under 30% useful for two weeks is demoted to FYI and Rob is told.

## 4. Learning loop

Click plus rule promotion is the right shape. Implicit signals (commits, payments) are evidence of a miss, never proof a flag was useful (Hu, Koren, Volinsky, ICDM 2008). Rule promotion: the same (source, pattern) useful three times, Claude drafts a regex or SQL predicate, Rob approves, the rule runs free and that class stops reaching any model. Thresholds come from each model's empirical distribution on the regression set, not a fixed 0.9 (https://arxiv.org/abs/2603.29559).

## 5. Bonsai

Not for this job unless a benchmark says otherwise. It costs the whole GPU while loaded, a server that corrupts under concurrency, a ModelManager and a canary. Keep it for any future bulk job of thousands of rows a day.

Unverified: the Ternary-Bonsai-2 model card (Hugging Face returned 401). OSV-Scanner supports composer.lock, package-lock.json, yarn.lock, pnpm-lock.yaml (https://google.github.io/osv-scanner/supported-languages-and-lockfiles/); the Windows binary was not confirmed. htdocs has 19 package-lock.json and 5 composer.lock files.

## Recommended design

1. `facts` table: id, source, kind, subject (project or client), salient hash, due date, severity, url, collected_at, state (new, shown, held, snoozed, dismissed, acted).
2. Four rule collectors, no model: SSL expiry (client domains plus `mail.sembr.co`), endoflife.date v1 (`https://endoflife.date/api/v1/products/php/`, nodejs, wordpress, laravel; fields `eolFrom`, `eoasFrom`, `isMaintained`) against lockfile versions and XAMPP's PHP, OSV-Scanner over the lockfiles, due dates and next actions from `_DASHBOARD.md` and `_PIPELINE.md`. Reviewer says these fire on day one: `mail.sembr.co` cert expired 2026-10-03, PHP 8.2 security support ends 2026-12-31 while XAMPP runs 8.2.12, a Roanoke follow-up overdue since June (not yet checked by the coordinator).
3. Candidate widener, add-only: card 64's keyword matcher plus a batched recall pass that may only append story-to-project candidates.
4. Daily read, one call: Sonnet, JSON schema, no tools, flat system prompt, through ClaudeRunner untrusted mode. Up to 5 top items plus held items, each by fact id with a verb, a date and one line of why. Code validates ids and URLs.
5. Page: top 5, "also seen" fold, uncertainty strip (max 5), useful/not and dismiss-with-reason buttons writing to a `labels` table with the prompt hash.
6. Weekly Opus call: miss audit and rule-promotion drafts for Rob to approve.
7. Optional `AnthropicClient` behind card 61's `ChatClient`, key from `.credentials`, disabled by default. Needs Rob's explicit approval.

First three things to build:
1. The `facts` table and the four rule collectors, rendered straight to the page with no model in the path.
2. The Haiku/Sonnet benchmark on the 696 rows (done 2026-10-13, see `attention-engine.md`).
3. The daily read with schema, id validation, top-5 cap, "also seen" fold and label buttons. Nothing else until a week of labels exists.
