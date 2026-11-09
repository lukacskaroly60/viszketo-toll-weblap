<?php
// SQLite adatbázis (egyetlen fájl a data/ mappában – nem kell MySQL-t beállítani).

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
    $pdo = new PDO('sqlite:' . DATA_DIR . '/viszketo-toll.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    try { $pdo->exec('PRAGMA journal_mode = WAL;'); } catch (PDOException $e) { /* néhány tárhelyen nem megy, nem baj */ }
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS writings (
      id           INTEGER PRIMARY KEY AUTOINCREMENT,
      slug         TEXT NOT NULL UNIQUE,
      title        TEXT NOT NULL,
      body         TEXT NOT NULL,
      published_at TEXT NOT NULL,                -- ÉÉÉÉ-HH-NN
      category     TEXT NOT NULL DEFAULT 'proza', -- 'proza' | 'vers'
      source_file  TEXT UNIQUE,                  -- importált fájl (ha onnan jött)
      edited       INTEGER NOT NULL DEFAULT 0,   -- 1 = Judit kézzel szerkesztette, az import nem írja felül
      hidden       INTEGER NOT NULL DEFAULT 0,   -- 1 = piszkozat, az olvasók nem látják
      created_at   TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS images (
      id         INTEGER PRIMARY KEY AUTOINCREMENT,
      writing_id INTEGER NOT NULL REFERENCES writings(id) ON DELETE CASCADE,
      path       TEXT NOT NULL,                  -- "kepek/x.jpg" vagy "feltoltesek/y.jpg"
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
    CREATE TABLE IF NOT EXISTS attempts (            -- sebességkorlát (belépés, regisztráció, lájk)
      bucket TEXT NOT NULL,
      at     INTEGER NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_comments_writing ON comments (writing_id, status);
    CREATE INDEX IF NOT EXISTS idx_comments_status  ON comments (status);
    CREATE INDEX IF NOT EXISTS idx_writings_list    ON writings (hidden, published_at DESC);
    CREATE INDEX IF NOT EXISTS idx_attempts         ON attempts (bucket, at);
    SQL);
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function tx(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
