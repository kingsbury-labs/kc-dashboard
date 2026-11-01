# 116. Cockpit AI news: real stories only (Normal)

## Objective
The AI news panel shows the day's real AI stories, ranked, with readable
headlines. Link-blog quotes, bare repo names and filler posts are gone.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 116
- `D:\Dev\htdocs\_kc-dashboard\config\feeds.php`, `src\News\Scorer.php`, `src\News\Clusterer.php`, `src\News\FetchNews.php`
- `D:\Dev\htdocs\_kc-dashboard\src\Cockpit\CockpitDaily.php` (`news`)

## Context
- Rob, 2026-11-01: "AI news is pulling some weird shit." Seen live: "Quoting
  Kyle Kingsbury", "dbreunig/nesformer", "datasette-llm 0.1a9",
  "nemotron-halloween". Most come from Simon Willison's `atom/everything` feed,
  which includes his quotes and link posts; switch that feed to his entries-only
  feed (`https://simonwillison.net/atom/entries/`). Check the URL returns a
  valid feed before using it (curl in your shell is fine; tests make no network
  calls).
- The panel today takes the newest `topic = ai` items. Rank them with the
  existing `Scorer::rank` (and `Clusterer` if it groups the same story across
  sources, showing the story once with its source count). Drop titles that are
  only a repo slug (`owner/name`), a version string, or start with "Quoting ".
  Show 6.

## Files you may touch
- `config/feeds.php`, `src/Cockpit/CockpitDaily.php`, `src/News/` (filter helper only)
- `public/cockpit/js/views.js`
- `tests/CockpitNewsTest.php` (new), `tests/fixtures/cockpit-news/` (new)
- `.claude/work/116-cockpit-ai-news/progress.md`, `result.md`

## Done-test
From plan.md card 116, word for word:

`c:\xampp\php\php.exe tests/run.php --filter CockpitNews` passes: against
fixture stories, the panel returns at most 6 ai stories in Scorer order; a
title that is a repo slug, a version string or starts with "Quoting " is
dropped; config/feeds.php no longer contains `atom/everything`.

Then the mutation check (drop the filter), name the failing tests, restore,
green. Filtered runs only.

## Boundaries
No commit, no push. Never list applies.

## Progress
Keep `progress.md` beside this file, one line per step. Resume from it if present.

## Report
`result.md` under 200 words: files, tests, mutation result. Say what was not done.
