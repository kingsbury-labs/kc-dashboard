The core instinct is right—rules first, models only around ambiguity—but the draft still gives models too much authority. A daily Claude call should not be the component deciding what disappears.

## What I would change

- **“Facts with confidence” is too lossy.** Store immutable observations, not generic facts: source, observed time, effective time, parser version, exact evidence, entity, and content hash. Keep extraction confidence, source reliability, urgency, and attention score separate. Crob’s own notes already identify repetition laundering and path-dependent confidence, and recommend append-only observations with derived beliefs ([HANDOFF.md](/D:/Dev/htdocs/crob/HANDOFF.md:21)).

- **Claude as the daily global gate creates one catastrophic failure point.** A malformed prompt, context overload, prompt injection, or one bad negative judgment can erase an entire category. Delimiters and schemas constrain syntax, not truth; OWASP explicitly says indirect prompt injection has no foolproof prompt-level prevention. [OWASP](https://genai.owasp.org/llmrisk/llm01-prompt-injection/)

- **Summarization before judgment destroys evidence.** Regex checking dates and amounts catches omissions of those fields, but not omitted negation, scope, exceptions, or responsibility. Prefer evidence extraction: exact quote/span plus normalized fields. Generate the display sentence last.

- **The proposed 5% audit cannot establish recall.** If there are 200 items, 5% is ten samples. Even zero discovered misses leaves a rough 95% upper bound near 30% for the underlying miss rate. More importantly, sampling summaries does not inspect items Bonsai suppressed. Audit the suppressed/raw pool.

- **“Discover unknown alert types” is underspecified.** The system can only discover what its collectors expose. It cannot discover accounting problems without an accounting source. Let models propose new candidate types or collectors; never let them silently install trusted rules.

- **The 90-day/200-label kickstart is likely counterproductive.** It mixes obsolete priorities with current ones, encourages rushed labels, and leaks later outcomes into retrospective decisions. Replay chronologically, label 30–50 stratified examples, then run two weeks in shadow mode.

- **The baseline implementation is not suitable.** `ai-ta` declares full confidence after five samples ([FingerprintGenerator.php](/D:/Dev/htdocs/ai-ta/lib/FingerprintGenerator.php:193)), averages anomalies into the baseline, and effectively freezes categorical fields ([FingerprintGenerator.php](/D:/Dev/htdocs/ai-ta/lib/FingerprintGenerator.php:776)). Use median/MAD or quantiles, persistence windows, seasonality where relevant, minimum sample counts, and baseline reset/change-point handling. NIST recommends robust scale estimates such as MAD when tails contain extreme values. [NIST](https://www.itl.nist.gov/div898/handbook/eda/section3/eda356.htm)

- **Raw OSV results will be noisy.** Rank by exact installed version, production versus development dependency, fix availability, internet exposure, direct/transitive status, and CISA KEV membership. OSV call analysis is limited by language and partly experimental; CISA recommends KEV as an input to prioritization. [OSV](https://google.github.io/osv-scanner/usage/scan-source), [CISA](https://www.cisa.gov/known-exploited-vulnerabilities-catalog)

- **`endoflife.date` is an aggregator, not authority.** Preserve its upstream source and independently confirm high-impact deadlines; it describes itself as community-maintained and aggregated. [endoflife.date](https://endoflife.date/bot)

- **One “overall 60%” score is not useful.** Track `precision@5`, explicit usefulness by rule, seeded-positive recall, audited suppressed-item miss rate, collector freshness, duplicate rate, and time to action.

## Cheapest design that preserves correctness

Process incrementally, but do not maintain opaque “model state.” SQLite is the state:

1. Append new/changed observations.
2. Re-evaluate open deadlines when they cross deterministic thresholds.
3. Run models only on new or materially changed unstructured evidence.
4. Reconcile all sources weekly to detect deletions, stale collectors, and missed jobs.

Your current corpus reinforces this: the 36 `CLAUDE.md` files are about 182 KB, while Node and Composer manifests/locks total roughly 6.5 MB. Parse them once into a compact entity/project index; never resend lockfiles or complete project documentation daily.

Prompt caching is not worth building initially. A once-daily request has no reusable 5-minute/1-hour cache. It becomes useful only when many independent item requests share a project dictionary in the same run. Current Claude caching requires identical prefixes and has ephemeral TTLs. [Prompt caching](https://platform.claude.com/docs/en/build-with-claude/prompt-caching)

Use “API batch” and “prompt batch” differently:

- Do not put 25 decisions into one model answer.
- Submit independent per-item requests through the Batch API when latency and retention are acceptable.
- This preserves per-item failures, evaluation, and confidence while receiving the 50% Batch discount. [Batch API](https://platform.claude.com/docs/en/build-with-claude/batch-processing)

For sensitive client data, Batch retains inputs/results for up to 29 days and is not ZDR-eligible; standard API retention is normally up to 30 days. Redact it, keep it local, or get explicit approval. [Anthropic retention](https://platform.claude.com/docs/en/manage-claude/api-and-data-retention)

## Stopping “this is fine”

Adopt an asymmetric rule:

- Deterministic positives always create candidates.
- Model positives may create candidates.
- Model negatives may lower rank but may never delete the observation or be the only reason it is invisible.
- High-impact negative decisions require either a second model, deterministic evidence, or suppressed-pool auditing.

The UI should show:

- At most 3–7 “Needs attention” items.
- A collapsed “Watchlist / 18 lower-priority candidates.”
- Coverage health: last successful scan per source, failures, stale sources, observations processed, candidates suppressed, and audit misses.
- Every item: action, why now, exact evidence, source, and “snooze until.”
- No repeat unless urgency, evidence, or state materially changes.

This follows the useful part of SRE practice: actionable items should interrupt attention; everything else is a ticket/watchlist/log. Excess noise eventually masks real alerts. [Google SRE](https://sre.google/sre-book/monitoring-distributed-systems/)

## Learning loop

Clicks are weak positive evidence, not correctness. No click is not a negative: the item may not have been seen. Recommender research treats implicit interactions as preference signals with varying confidence, not ground truth. [Hu, Koren, Volinsky](https://yifanhu.net/PUB/cf.pdf)

Use explicit reasons:

- useful/actioned
- already knew/duplicate
- correct but not actionable
- too early
- irrelevant
- wrong evidence
- snooze
- resolved

A related commit or invoice payment is a weak outcome signal only if the item was shown first and project/entity/time match. Never interpret absence as rejection.

For label selection, combine:

- model/rule disagreement;
- items near the display threshold;
- high-impact suppressed items;
- novel sources/types;
- a permanent random sample.

Uncertainty-only active learning over-selects odd outliers; density/diversity and random exploration are needed. [Settles survey](https://burrsettles.com/pub/settles.activelearning.pdf)

Rule promotion is good, with one addition: Claude proposes a rule, but promotion requires historical replay, precision/recall results, generated regression cases, and Rob’s approval. Online A/B testing is mostly theatre with one user and low volume. Also, `PromptTemplate.php` does contain a `recordPerformance()` facility ([PromptTemplate.php](/D:/Dev/htdocs/ai-ta/lib/PromptTemplate.php:311)); the missing piece is trustworthy outcome wiring and sufficient sample size.

## Is Bonsai worth keeping?

Not in the production critical path.

At an assumed 300 input and 40 output tokens for each of 696 rows, current Claude Haiku 5.5 would cost approximately:

- synchronous: **3.5¢**
- Batch API: **1.7¢**

Haiku 5.5 currently costs $0.10/$0.50 per million input/output tokens below 100K prompt tokens; Batch halves that. It supports structured outputs. [Current pricing](https://platform.claude.com/docs/en/about-claude/pricing), [structured outputs](https://platform.claude.com/docs/en/build-with-claude/structured-outputs)

Its accuracy on your task is **unverified**. Run the same 696-row test and a target-specific attention/evidence set before trusting it.

Bonsai remains useful only as:

- a privacy-preserving extractor for text that must stay local;
- an experimental recall expander whose positive results are added;
- an offline fallback when the API is unavailable.

Its negative answers should have no effect. Given 7.6/8.1 GB VRAM use, `-np 1`, corruption under concurrency, and maintenance overhead, cents of Haiku usage beat operating it continuously. Remove gpt-oss from routing entirely; your measurements reject it, and OpenAI’s official guidance places gpt-oss-20b around 16 GB VRAM, explaining the CPU spill on this machine. [OpenAI documentation](https://developers.openai.com/cookbook/articles/gpt-oss/run-transformers)

Direct API use clearly benefits this design: scheduled structured output, Batch, isolated quotas, exact accounting, and no consumption of Rob’s coding allowance. Anthropic says Claude/Claude Code limits are shared, and monthly API credits cannot be consumed through Claude Code. [Claude Code limits](https://support.claude.com/en/articles/11145838-use-claude-code-with-your-pro-or-max-plan) I would therefore request a narrow reversal of the API prohibition: dedicated workspace/key, no auto-reload, strict monthly cap, no tools, and redacted/minimal inputs.

## Recommended design

- **Collectors:** append-only observations plus coverage status.
- **Normalizer:** deterministic entities, exact versions, obligations, dates, provenance, and contradictions.
- **Rules:** expiry, EOL, due dates, certificate state, prioritized vulnerabilities, and persistent robust anomalies.
- **Retrieval:** high-recall entity/package/domain/CVE matching over news and notes.
- **Haiku 5.5:** per-item extraction and candidate generation with exact evidence spans; never authoritative suppression.
- **Sonnet:** only ambiguous high-impact verification and weekly rule/collector proposals. No daily global “decide everything” call.
- **Ranker:** code-owned scoring, deduplication, cooldowns, state transitions, and a hard display budget.
- **Audit:** regression set, seeded positives, stratified suppressed-item review, source freshness, and model/version tracking.
- **Bonsai:** optional local/private laboratory path.
- **`claude -p`:** Rob’s chat and manual fallback, not scheduled production infrastructure.

Daily cap: approximately **30K input and 3K output tokens**, split between Haiku and Sonnet. At current synchronous API prices, budget about **$0.03/day or $1/month**; public, non-sensitive Batch work can roughly halve that.

The first three things to build:

1. The observation/candidate schema with source-health and lifecycle states.
2. Three deterministic end-to-end rules: certificate expiry, client due/owed items, and vulnerability prioritization.
3. A shadow-mode evaluator showing top candidates, suppressed candidates, seeded misses, and explicit feedback—before adding Bonsai, Claude ranking, baselines, or 90-day backfill.