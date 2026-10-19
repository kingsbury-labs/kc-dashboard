CREATE TABLE IF NOT EXISTS asks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER,
    project TEXT NOT NULL DEFAULT '',
    cwd_parent TEXT NOT NULL,
    cwd_name TEXT NOT NULL,
    busy_if_dirty TEXT,
    question TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed')),
    answer TEXT,
    error TEXT,
    usage TEXT,
    created_at INTEGER NOT NULL,
    answered_at INTEGER
);
CREATE INDEX IF NOT EXISTS asks_status_id ON asks (status, id);
