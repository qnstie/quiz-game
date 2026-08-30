-- Allow TEST project state (editable like SETUP, joinable/answerable like ACTIVE).
CREATE TABLE projects_new (
  id              TEXT PRIMARY KEY,
  slug            TEXT NOT NULL UNIQUE,
  title           TEXT NOT NULL,
  state           TEXT NOT NULL DEFAULT 'SETUP'
                  CHECK (state IN ('SETUP','TEST','ACTIVE','CLOSED','REVEALED')),
  db_path         TEXT NOT NULL,
  shuffle_quizzes INTEGER NOT NULL DEFAULT 0,
  require_pin     INTEGER NOT NULL DEFAULT 0,
  results_stale   INTEGER NOT NULL DEFAULT 0,
  created_at      TEXT NOT NULL,
  updated_at      TEXT NOT NULL,
  closed_at       TEXT,
  revealed_at     TEXT
);

INSERT INTO projects_new (
  id, slug, title, state, db_path, shuffle_quizzes, require_pin, results_stale,
  created_at, updated_at, closed_at, revealed_at
)
SELECT
  id, slug, title, state, db_path, shuffle_quizzes, require_pin, results_stale,
  created_at, updated_at, closed_at, revealed_at
FROM projects;

DROP TABLE projects;
ALTER TABLE projects_new RENAME TO projects;
