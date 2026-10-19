-- One row per thing that may need Rob, keyed by the id its source gives it. due_date is YYYY-MM-DD.
-- crossing is the last of the 30, 7 and 1 days-out marks this fact has re-surfaced at.
CREATE TABLE IF NOT EXISTS facts (
    id TEXT PRIMARY KEY,
    source TEXT NOT NULL,
    kind TEXT NOT NULL,
    subject TEXT NOT NULL,
    salient_hash TEXT NOT NULL,
    due_date TEXT,
    severity TEXT NOT NULL CHECK (severity IN ('high', 'med', 'low')),
    amount_cents INTEGER,
    evidence TEXT,
    url TEXT,
    parser_version TEXT,
    collected_at INTEGER NOT NULL,
    state TEXT NOT NULL DEFAULT 'new' CHECK (state IN ('new', 'shown', 'held', 'snoozed', 'dismissed', 'acted')),
    snoozed_until INTEGER,
    crossing INTEGER
);
CREATE INDEX IF NOT EXISTS facts_state ON facts (state);

-- Every collection of a fact, appended and never updated: what the parser saw and when.
CREATE TABLE IF NOT EXISTS observations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fact_id TEXT NOT NULL,
    salient_hash TEXT NOT NULL,
    severity TEXT NOT NULL,
    evidence TEXT,
    parser_version TEXT,
    collected_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS observations_fact ON observations (fact_id, id);

CREATE TABLE IF NOT EXISTS sources (
    name TEXT PRIMARY KEY,
    last_ok_at INTEGER,
    last_error TEXT,
    status TEXT NOT NULL DEFAULT 'never' CHECK (status IN ('never', 'ok', 'error'))
);

-- Rob's labels. The hash is the fact's salient hash when he gave it, so a label applies to what he saw.
CREATE TABLE IF NOT EXISTS feedback (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fact_id TEXT NOT NULL,
    salient_hash TEXT NOT NULL,
    reason TEXT NOT NULL CHECK (reason IN ('useful', 'already knew', 'not actionable', 'too early', 'irrelevant',
        'wrong evidence', 'resolved')),
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS feedback_fact ON feedback (fact_id, id);
