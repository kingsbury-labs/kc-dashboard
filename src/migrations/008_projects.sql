CREATE TABLE IF NOT EXISTS projects (
    slug TEXT PRIMARY KEY,
    path TEXT NOT NULL,
    branch TEXT,
    ahead INTEGER,
    behind INTEGER,
    dirty INTEGER,
    last_sha TEXT,
    last_date TEXT,
    last_subject TEXT,
    has_claude INTEGER NOT NULL DEFAULT 0,
    loop_state TEXT CHECK (loop_state IS NULL OR loop_state IN ('none', 'running', 'stopped', 'stale')),
    loop_card TEXT,
    loop_note TEXT,
    checklist_cards INTEGER,
    checklist_passing INTEGER,
    scanned_at INTEGER NOT NULL,
    error TEXT
);
