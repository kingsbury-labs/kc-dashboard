-- One row per story a feed listed, keyed by its source and canonical url (or title, for an item with no usable url).
-- url is null unless it was http:// or https://. canon is the url with utm_* and the fragment stripped, for
-- clustering. published_at is the feed's own date, null when it gave none it could parse.
CREATE TABLE IF NOT EXISTS news_items (
    id TEXT PRIMARY KEY,
    source TEXT NOT NULL,
    topic TEXT NOT NULL,
    title TEXT NOT NULL,
    url TEXT,
    canon TEXT,
    published_at INTEGER,
    points INTEGER,
    first_seen INTEGER NOT NULL,
    last_seen INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS news_items_seen ON news_items (first_seen);

-- Each feed's last run, so a dead feed shows instead of going quiet.
CREATE TABLE IF NOT EXISTS news_feeds (
    name TEXT PRIMARY KEY,
    last_ok_at INTEGER,
    last_error TEXT,
    status TEXT NOT NULL DEFAULT 'never' CHECK (status IN ('never', 'ok', 'error'))
);
