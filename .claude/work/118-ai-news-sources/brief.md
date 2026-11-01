# 118. AI news sources from Rob's curated list (Small)

## Objective
The AI news panel draws from a stronger set of AI feeds, picked from the list
Rob shared, each checked to parse before it is added.

## Read first
- `D:\Dev\htdocs\_kc-dashboard\CLAUDE.md`, `D:\Dev\htdocs\_kc-dashboard\.claude\project.md`
- `D:\Dev\htdocs\_kc-dashboard\.claude\work\plan.md`, card 118
- `D:\Dev\htdocs\_kc-dashboard\config\feeds.php`, `.claude\work\116-cockpit-ai-news\result.md`

## Context
- Rob, 2026-11-01, shared https://gist.github.com/danbarbarito/c7f2fd9f6e0d25a1240b910df5b93938 :
  a JSON list of about 100 AI feeds (`feed_url`, `site_url`, `title`). Raw:
  https://gist.githubusercontent.com/danbarbarito/c7f2fd9f6e0d25a1240b910df5b93938/raw
- Pick at most 15 new `topic => 'ai'` feeds that publish real stories (labs,
  serious newsletters and publications; skip podcasts, aggregators of
  aggregators, and feeds already in config). Fetch each once with curl in your
  shell; keep only those that return a valid RSS or Atom document with an item
  dated in the last 30 days. List kept and dropped with the reason in result.md.

## Files you may touch
- `config/feeds.php`
- `.claude/work/118-ai-news-sources/progress.md`, `result.md`

## Done-test
From plan.md card 118, word for word:

`c:\xampp\php\php.exe tests/run.php --filter Feed` passes, and
`config/feeds.php` has at most 15 more `ai` feeds than before, each a https
URL from the gist.

## Boundaries
No commit, no push. Nothing installed. Never list applies.

## Progress
Keep `progress.md` beside this file. Resume from it if present.

## Report
`result.md` under 150 words: feeds kept and dropped, with reasons.
