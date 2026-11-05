<?php
declare(strict_types=1);

// NP-specific schema; connection and path validation come from Common/src/sqlite.php.
const ITEMS_SCHEMA = 'CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL CHECK(length(name) BETWEEN 1 AND 120),
    price_cents INTEGER NOT NULL CHECK(price_cents >= 0),
    link TEXT NOT NULL DEFAULT \'\' CHECK(length(link) <= 2048),
    position INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)';
