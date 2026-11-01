Files: config/feeds.php (atom/entries/, curl returned a valid Atom feed), src/Cockpit/CockpitDaily.php (news ranked by Scorer::rank, limit 6), src/News/TitleFilter.php (new), tests/CockpitNewsTest.php and tests/fixtures/cockpit-news/items.json (new). views.js needed no change.

Tests: 4 added, filtered CockpitNews and CockpitDaily both green.

Mutation: removed the TitleFilter call; "at most 6 ... in Scorer order" and "slugs, versions, bare names and Quoting dropped" failed. Restored, green.

Call made: Clusterer not used. It folds same-source look-alike titles, and the existing CockpitDailyTest fixture (six "Older AI story N" rows from one source) expects 6 items, a file I may not edit. So no cross-source story merging or source count in the panel yet.

Also dropped: any single-word title. Not done: full suite.
