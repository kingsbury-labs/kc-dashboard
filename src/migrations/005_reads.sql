-- One row per daily read, kept whether or not the reply was usable. ids_sent is the JSON list of every fact id in
-- the prompt; known maps each of those ids to the fingerprint it had, so the next read sends only what changed.
-- ranking is the checked {id: {rank, reason}} map, NULL when the call failed or the reply was not valid, and then
-- the previous read's ranking still stands. result is the model's raw text, kept for audit and never rendered.
CREATE TABLE IF NOT EXISTS reads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at INTEGER NOT NULL,
    prompt_hash TEXT NOT NULL,
    ids_sent TEXT NOT NULL,
    known TEXT NOT NULL,
    result TEXT,
    ranking TEXT,
    usage TEXT,
    error TEXT
);
CREATE INDEX IF NOT EXISTS reads_created ON reads (created_at, id);
