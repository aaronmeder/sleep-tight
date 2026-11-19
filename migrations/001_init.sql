-- One row per night, keyed by the wake-up date.
CREATE TABLE entries (
    date       TEXT PRIMARY KEY,            -- YYYY-MM-DD (morning you woke up)
    hours      REAL    NOT NULL,
    breaks     INTEGER NOT NULL DEFAULT 0,
    quality    INTEGER NOT NULL,            -- 1-100
    clothing   TEXT    NOT NULL DEFAULT '[]', -- JSON arrays of tag slugs
    day        TEXT    NOT NULL DEFAULT '[]',
    evening    TEXT    NOT NULL DEFAULT '[]',
    specifics  TEXT    NOT NULL DEFAULT '[]',
    note       TEXT    NOT NULL DEFAULT '',
    updated_at INTEGER NOT NULL,            -- unix time, drives sync (newest wins)
    deleted    INTEGER NOT NULL DEFAULT 0   -- tombstone so deletes sync too
);

CREATE TABLE settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE sessions (
    token_hash TEXT PRIMARY KEY,
    created_at INTEGER NOT NULL
);
