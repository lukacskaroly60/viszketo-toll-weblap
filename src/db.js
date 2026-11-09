// SQLite adatbázis (Node beépített node:sqlite modulja – nincs natív függőség).
import { DatabaseSync } from 'node:sqlite';
import fs from 'node:fs';
import path from 'node:path';
import { DATA_DIR } from './config.js';

fs.mkdirSync(DATA_DIR, { recursive: true });

export const db = new DatabaseSync(path.join(DATA_DIR, 'viszketo-toll.db'));

db.exec(`
  PRAGMA journal_mode = WAL;
  PRAGMA foreign_keys = ON;

  CREATE TABLE IF NOT EXISTS writings (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    slug         TEXT NOT NULL UNIQUE,
    title        TEXT NOT NULL,
    body         TEXT NOT NULL,
    published_at TEXT NOT NULL,            -- YYYY-MM-DD
    source_file  TEXT UNIQUE,              -- importált fájl neve (ha onnan jött)
    edited       INTEGER NOT NULL DEFAULT 0, -- 1 = Judit kézzel szerkesztette, az import nem írja felül
    hidden       INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT NOT NULL DEFAULT (datetime('now'))
  );

  CREATE TABLE IF NOT EXISTS images (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    writing_id INTEGER NOT NULL REFERENCES writings(id) ON DELETE CASCADE,
    path       TEXT NOT NULL,              -- pl. "kepek/valami.jpg" vagy "feltoltesek/123.jpg"
    position   INTEGER NOT NULL DEFAULT 0,
    UNIQUE (writing_id, path)
  );

  CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT NOT NULL UNIQUE COLLATE NOCASE,
    name          TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'reader', -- 'reader' | 'admin'
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
  );

  CREATE TABLE IF NOT EXISTS sessions (
    token      TEXT PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at TEXT NOT NULL
  );

  CREATE TABLE IF NOT EXISTS likes (
    writing_id INTEGER NOT NULL REFERENCES writings(id) ON DELETE CASCADE,
    visitor_id TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (writing_id, visitor_id)
  );

  CREATE TABLE IF NOT EXISTS comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    writing_id  INTEGER NOT NULL REFERENCES writings(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body        TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'pending', -- 'pending' | 'approved' | 'rejected'
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    reviewed_at TEXT
  );

  CREATE INDEX IF NOT EXISTS idx_comments_writing ON comments (writing_id, status);
  CREATE INDEX IF NOT EXISTS idx_comments_status  ON comments (status);
  CREATE INDEX IF NOT EXISTS idx_writings_date    ON writings (published_at DESC);
`);

// Későbbi bővítések (meglévő adatbázisnál is lefutnak)
const cols = db.prepare('PRAGMA table_info(writings)').all().map((c) => c.name);
if (!cols.includes('category')) {
  db.exec(`ALTER TABLE writings ADD COLUMN category TEXT NOT NULL DEFAULT 'proza'`); // 'proza' | 'vers'
}

export const CATEGORIES = { proza: 'Próza', vers: 'Versek' };

/** Tranzakció segédfüggvény. */
export function tx(fn) {
  db.exec('BEGIN');
  try {
    const r = fn();
    db.exec('COMMIT');
    return r;
  } catch (e) {
    db.exec('ROLLBACK');
    throw e;
  }
}
