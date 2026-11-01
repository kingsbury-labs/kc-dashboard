-- The project knowledge graph (card 117). One typed node table, one typed edge table. Each edge names the reader it
-- came from (source), so a rebuild replaces one source's edges and leaves the rest; confirmed is Rob's click.
CREATE TABLE IF NOT EXISTS graph_node (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    key TEXT NOT NULL,
    label TEXT NOT NULL,
    UNIQUE (type, key)
);

CREATE TABLE IF NOT EXISTS graph_edge (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    src INTEGER NOT NULL REFERENCES graph_node(id),
    dst INTEGER NOT NULL REFERENCES graph_node(id),
    type TEXT NOT NULL,
    source TEXT NOT NULL,
    weight REAL NOT NULL DEFAULT 1,
    confirmed INTEGER NOT NULL DEFAULT 0,
    UNIQUE (src, dst, type, source)
);
CREATE INDEX IF NOT EXISTS graph_edge_src ON graph_edge (src);
CREATE INDEX IF NOT EXISTS graph_edge_dst ON graph_edge (dst);

-- Other names for a node: a second address for the same person, only from an explicit same-person record.
CREATE TABLE IF NOT EXISTS graph_alias (
    alias TEXT PRIMARY KEY,
    node INTEGER NOT NULL REFERENCES graph_node(id)
);

-- Rob's corrections as he made them: sender address => project, or null for not mine.
CREATE TABLE IF NOT EXISTS graph_correction (
    addr TEXT PRIMARY KEY,
    project TEXT,
    at INTEGER NOT NULL
);

-- Where each reader got to: last UID per mailbox, file mtimes.
CREATE TABLE IF NOT EXISTS graph_state (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
