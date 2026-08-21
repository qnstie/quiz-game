CREATE TABLE profile (
  key   TEXT PRIMARY KEY,
  value TEXT
);

CREATE TABLE answers (
  quiz_id      TEXT PRIMARY KEY,
  option_id    TEXT NOT NULL,
  answered_at  TEXT NOT NULL,
  updated_at   TEXT NOT NULL,
  change_count INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE activity (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  at TEXT NOT NULL,
  kind TEXT NOT NULL,
  detail TEXT
);
