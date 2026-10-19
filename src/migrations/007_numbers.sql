-- The latest value of each number, keyed by its source ('ga4:<slug>', 'search_console:<slug>', 'status',
-- 'bugsink:<project>') and metric ('sessions', 'top_page:01', ...). label carries a top page's path or a status word.
-- A good run replaces its source's rows whole; a failed run leaves them and records the error in number_sources.
CREATE TABLE IF NOT EXISTS numbers (
    source TEXT NOT NULL,
    metric TEXT NOT NULL,
    value REAL,
    label TEXT,
    collected_at INTEGER NOT NULL,
    PRIMARY KEY (source, metric)
);

-- Each number source's last run, so a property that stopped reporting shows its error instead of old numbers alone.
CREATE TABLE IF NOT EXISTS number_sources (
    name TEXT PRIMARY KEY,
    last_ok_at INTEGER,
    last_error TEXT,
    status TEXT NOT NULL DEFAULT 'never' CHECK (status IN ('never', 'ok', 'error'))
);
