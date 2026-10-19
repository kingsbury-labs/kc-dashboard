# 63. News: result

Files: `src/News/{FeedParser,FetchNews,Clusterer,Scorer}.php` (NewsApi in Scorer.php), `bin/fetch-news.php`, `config/feeds.php`, `src/migrations/006_news.sql`, `public/api/news.php`, `public/assets/news.js`, `src/View.php` (no-JS Headlines panel), `public/index.php`, `tests/fixtures/feeds/`, four test files.

Round one mutations each failed a test: size cap, feed https check, story url check, utm strip, source dedupe, loopback check. View.php was edited with a Python heredoc, not Edit.

## Round two

Fixed: points only from `format => algolia` feeds, capped at 2000. Dates, the window and pruning use min(published_at, first_seen). Greedy clustering against each story's first item, overlap over the union of words, front item by first_seen, one item per source. UTF-8 only, no declared other encoding, a null entity loader that gets restored, a structural doctype check, flags pinned. The safeUrl host is decoded and must be ASCII labels. 60s cache.

Call: only news.php writes the cache. index.php reads it, because the guard sweep forbids a navigation writing to data/.

UTF-16 case: built at runtime from xxe.xml. Write can't produce UTF-16.

Mutations, each failed, restored: smaller-set overlap (attacker test), feed date trusted (future-date test), prune by first_seen, points gate, points cap, one-per-source, cache lifetime. Decode removal first survived; I added a test. The owed XXE mutation ran: failed "XXE fixture... refused", no leak.

Full suite 314/314.
