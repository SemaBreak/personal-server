<?php
declare(strict_types=1);

// RSS-specific schema. Timestamps are UTC 'YYYY-MM-DD HH:MM:SS'.
const RSS_SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS feeds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    url TEXT NOT NULL UNIQUE CHECK(length(url) <= 2048),
    title TEXT NOT NULL DEFAULT '',
    etag TEXT,
    last_modified TEXT,
    last_fetched TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    feed_id INTEGER NOT NULL,
    guid TEXT NOT NULL,
    title TEXT NOT NULL,
    link TEXT NOT NULL,
    published_at TEXT NOT NULL,
    UNIQUE(feed_id, guid)
);
CREATE INDEX IF NOT EXISTS items_by_date ON items (published_at DESC, id DESC);
CREATE TABLE IF NOT EXISTS meta (
    name TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
INSERT OR IGNORE INTO meta (name, value) VALUES ('refresh_started', '0');
SQL;
