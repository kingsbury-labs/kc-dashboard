-- One briefing per day, as it was written to Briefings/YYYY-MM-DD.md. facts holds every attention fact id it was
-- built from; snapshot holds the loop pass counts and number values, so tomorrow's briefing can say what moved.
CREATE TABLE IF NOT EXISTS briefings (
    day TEXT PRIMARY KEY,
    created_at INTEGER NOT NULL,
    body TEXT NOT NULL,
    facts TEXT NOT NULL,
    snapshot TEXT NOT NULL
);

-- What Rob asked the dashboard to remember, as written to Memory/YYYY-MM.md (already neutralised).
CREATE TABLE IF NOT EXISTS memories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at INTEGER NOT NULL,
    text TEXT NOT NULL
);

CREATE VIRTUAL TABLE IF NOT EXISTS memories_fts USING fts5(text, content='memories', content_rowid='id');
