CREATE TABLE superusers (
  id            TEXT PRIMARY KEY,
  email         TEXT NOT NULL UNIQUE COLLATE NOCASE,
  password_hash TEXT NOT NULL,
  password_algo TEXT NOT NULL,
  display_name  TEXT,
  is_active     INTEGER NOT NULL DEFAULT 1,
  created_at    TEXT NOT NULL,
  last_login_at TEXT
);

CREATE TABLE projects (
  id              TEXT PRIMARY KEY,
  slug            TEXT NOT NULL UNIQUE,
  title           TEXT NOT NULL,
  state           TEXT NOT NULL DEFAULT 'SETUP'
                  CHECK (state IN ('SETUP','ACTIVE','CLOSED','REVEALED')),
  db_path         TEXT NOT NULL,
  shuffle_quizzes INTEGER NOT NULL DEFAULT 0,
  require_pin     INTEGER NOT NULL DEFAULT 0,
  results_stale   INTEGER NOT NULL DEFAULT 0,
  created_at      TEXT NOT NULL,
  updated_at      TEXT NOT NULL,
  closed_at       TEXT,
  revealed_at     TEXT
);

CREATE TABLE app_settings (
  key   TEXT PRIMARY KEY,
  value TEXT
);

CREATE TABLE rate_limits (
  bucket_key   TEXT NOT NULL,
  window_start INTEGER NOT NULL,
  count        INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket_key, window_start)
);
