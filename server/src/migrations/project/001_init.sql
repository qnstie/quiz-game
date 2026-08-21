CREATE TABLE meta (
  key   TEXT PRIMARY KEY,
  value TEXT
);

CREATE TABLE quizzes (
  id               TEXT PRIMARY KEY,
  position         INTEGER NOT NULL,
  title            TEXT NOT NULL,
  description_html TEXT NOT NULL DEFAULT '',
  explanation_html TEXT NOT NULL DEFAULT '',
  points           INTEGER NOT NULL DEFAULT 1,
  shuffle_options  INTEGER NOT NULL DEFAULT 1,
  created_at       TEXT NOT NULL,
  updated_at       TEXT NOT NULL
);
CREATE INDEX idx_quizzes_position ON quizzes(position);

CREATE TABLE options (
  id            TEXT PRIMARY KEY,
  quiz_id       TEXT NOT NULL REFERENCES quizzes(id) ON DELETE CASCADE,
  position      INTEGER NOT NULL,
  label_html    TEXT NOT NULL DEFAULT '',
  is_correct    INTEGER NOT NULL DEFAULT 0,
  feedback_html TEXT NOT NULL DEFAULT '',
  created_at    TEXT NOT NULL,
  updated_at    TEXT NOT NULL
);
CREATE INDEX idx_options_quiz ON options(quiz_id, position);

CREATE TABLE users (
  id           TEXT PRIMARY KEY,
  name_key     TEXT NOT NULL UNIQUE,
  name_display TEXT NOT NULL,
  token_hash   TEXT NOT NULL,
  pin_hash     TEXT,
  shuffle_seed INTEGER NOT NULL,
  db_path      TEXT NOT NULL,
  created_at   TEXT NOT NULL,
  last_seen_at TEXT
);

CREATE TABLE media (
  id          TEXT PRIMARY KEY,
  filename    TEXT NOT NULL,
  stored_path TEXT NOT NULL,
  mime        TEXT NOT NULL,
  bytes       INTEGER NOT NULL,
  width       INTEGER,
  height      INTEGER,
  duration_s  REAL,
  uploaded_by TEXT,
  created_at  TEXT NOT NULL
);

CREATE TABLE results_user (
  user_id        TEXT PRIMARY KEY,
  score          INTEGER NOT NULL,
  max_score      INTEGER NOT NULL,
  answered_count INTEGER NOT NULL,
  correct_count  INTEGER NOT NULL,
  rank           INTEGER NOT NULL,
  computed_at    TEXT NOT NULL
);

CREATE TABLE results_answer (
  user_id     TEXT NOT NULL,
  quiz_id     TEXT NOT NULL,
  option_id   TEXT,
  is_correct  INTEGER NOT NULL DEFAULT 0,
  answered_at TEXT,
  PRIMARY KEY (user_id, quiz_id)
);
CREATE INDEX idx_results_answer_quiz ON results_answer(quiz_id);

CREATE TABLE results_option_stats (
  quiz_id    TEXT NOT NULL,
  option_id  TEXT NOT NULL,
  pick_count INTEGER NOT NULL,
  PRIMARY KEY (quiz_id, option_id)
);
