# Family Quiz — Implementation Plan (rev. 4)

**Target:** installable PWA + desktop browser web app for a family event, September 2026.
**Purpose:** serve single-choice quizzes about family stories, collect answers from named (non-authenticated) participants, close the quiz, compute scores, reveal results on a projector.
**Audience of this document:** Cursor / the implementing developer. Everything below is prescriptive.

**rev. 4 changes — deployment target moved from a Docker/VPS backend to DreamHost Shared Hosting.** This is not a cosmetic change: it removes Node.js from the server entirely and replaces the backend with PHP, removes Docker, and changes several implementation details (auth hashing, HTML sanitisation, rate limiting, SQLite journal mode). Section 0 below explains why, with what was actually verified rather than assumed. Everything in the Frontend section (§7) is essentially untouched — the React PWA doesn't care what language its API is written in.

---

## 0. Why PHP, and what was verified before deciding

DreamHost's own documentation (checked directly, not from memory) states plainly: *"Node.js apps can only be installed onto Managed VPS and Dedicated Servers"* — not Shared. Attempting to run a persistent Node process on a Shared plan doesn't degrade gracefully, it's simply unsupported. So the instinct behind this change was correct, and there's no clever workaround worth chasing (serverless Node-on-shared tricks exist in blog posts but are fragile and unsupported — not something to build a family event on).

What Shared *does* give us, confirmed from DreamHost's docs:
- **PHP**, with a version selectable per-domain in the panel (use **PHP 8.3**).
- **SQLite**, pre-installed with PHP bindings (`PDO_SQLITE`) — so the entire three-tier database design (app DB / project DB / per-participant DB) carries over unchanged. This is good news: PHP's one-process-per-request model actually suits a filesystem-of-SQLite-files better than Node's persistent pool did — there's no connection cache to manage, no LRU eviction logic, no process to keep alive.
- **SSH access**, which means Composer, git, and a real deploy workflow are available — this isn't classic FTP-only hosting.
- **Free Let's Encrypt TLS**, **cron jobs**, and a configurable **web directory** per domain (so the app's docroot can be a `public/` subfolder while data and code sit outside it) — all through the panel.

One thing flagged rather than asserted: shared-hosting home directories are, on many providers, backed by networked storage, and SQLite's locking (especially WAL mode) is well documented to behave unreliably over NFS-like filesystems — this is a generic SQLite caveat, not something DreamHost's docs confirm or deny one way or the other for their current infrastructure. Given 25 participants and no cost to being conservative, §3.4 below designs around this risk (no WAL, short transactions, `busy_timeout`, and a first-day smoke test) rather than gambling on it not applying.

---

## 1. Glossary — read this before anything else

The domain has exactly three content levels. Use these names everywhere in code, tables and UI.

| Term | Definition |
|---|---|
| **Project** | A whole event's quiz set. Has a title, an HTML description, a state, and N quizzes. Multiple projects can exist; only one is served to participants at a time. |
| **Quiz** | **One single-choice item.** Has a title, an HTML description (the story / the actual question, may contain text, images, audio, video), and **exactly four answer options**, of which **exactly one is correct**. A participant submits **one** answer per quiz, and the next quiz appears automatically. |
| **Option** | One of the four answers. An HTML field (text/image/audio/video). Carries `is_correct` and an optional `feedback_html` — the superuser's comment on that choice, revealed only after wrap-up. |

There is no intermediate "question" entity. The quiz *is* the question; its description holds the prompt.

**Randomisation:** the four options are shuffled per participant, controlled by a per-quiz `shuffle_options` flag (**default on**; turn it off for options with a natural order, e.g. years or "youngest → oldest"). Quiz order follows the superuser's `position` by default (a `shuffle_quizzes` project flag exists, default off).

### Scale (confirmed)
- ≤ 25 participants
- ≤ 100 quizzes, likely far fewer
- One event, one evening, good wifi/4G

---

## 2. Confirmed decisions

| Topic | Decision |
|---|---|
| Frontend | **Vite + React 18 + TypeScript** (SPA), built locally, deployed as static files |
| Backend | **PHP 8.3**, **Slim 4** micro-framework + PHP-DI, **PDO SQLite** |
| Storage | SQLite files on disk: one app DB, one DB per project, one DB per participant |
| Wrap-up | Server iterates participant DBs, `ATTACH`-ing each onto the project connection — one SQL statement per participant |
| Connectivity | Online-only. No offline sync layer. |
| Media | **Both** — file upload (hosted by the app) and external links / embeds |
| **Hosting** | **DreamHost Shared**, single domain (or subdomain), no Docker, no VPS |
| Auth (superuser) | email + password; `PASSWORD_ARGON2ID` if the PHP build supports it, `PASSWORD_BCRYPT` fallback (see §8); JWT in httpOnly cookie |
| Auth (participant) | unique display name → opaque token in httpOnly cookie + localStorage mirror |

### Defaults assumed (unchanged, correct them if wrong)

1. **Scoring:** 1 point per correct answer. Max score = number of quizzes at close time. No timing bonus, no negative marking. Ties share a rank.
2. **Order stability:** derived from a per-participant `shuffle_seed`, so resuming on another device shows the same option order.
3. **Identity:** claiming an existing name **resumes that participant**. No verification, as specified. An optional 4-digit PIN exists behind a project flag `require_pin`, default **off**.
4. **Editing after answers exist:** allowed, answers preserved. If the correct option changes, scores are simply recomputed at close.
5. **Deleting** a quiz/option that has answers: allowed but requires typed confirmation; orphaned answers are ignored in scoring.
6. **Changing an answer** while the project is ACTIVE is allowed.
7. **UI language:** Admin — English only. Participant — Polish + English, user-selectable (see §7.8).

---

## 3. Application states

Single state per project, authoritative in the app DB. Unchanged from the Node version — this is a data-model concept, not a hosting concept.

```
        ┌──────────┐   open    ┌────────┐  wrap up  ┌────────┐  reveal  ┌──────────┐
        │ SETUP    │──────────▶│ ACTIVE │──────────▶│ CLOSED │─────────▶│ REVEALED │
        │(blocked) │◀──────────│        │◀──────────│        │◀─────────│          │
        └────┬─────┘           └────────┘           └────────┘          └──────────┘
             │                    ▲
             │     TEST           │
             └────────────────────┘
           (editable + joinable)
```

| State | Participants | Superuser |
|---|---|---|
| `SETUP` (blocked) | "The quiz is being updated, back in a moment" screen. Any write returns **423 Locked**. | Full content CRUD. Project switcher visible. |
| `TEST` | Join, answer, resume like ACTIVE. Sees live content while admins still edit. | Full content CRUD (same as SETUP). Sets public/active project. |
| `ACTIVE` | Join, answer, resume, navigate. No results anywhere. | Content CRUD **blocked** (must switch to SETUP or TEST first). Live progress view. |
| `CLOSED` | "Answers are closed, results coming soon." | Scores computed. Full results, per-participant drill-down, presentation mode. |
| `REVEALED` | Own full results (own answers, correctness, feedback comments) **and** the leaderboard (names + scores only). | Same as CLOSED. |

Rules:
- Any transition is allowed in either direction; backwards transitions require a confirm dialog.
- Entering `CLOSED` runs the scoring job synchronously and stamps `results_computed_at`.
- Entering `ACTIVE` or `TEST` from `CLOSED`/`REVEALED` sets `results_stale = 1` without deleting results; recomputed on the next close.
- `SETUP` is the only state where participants are blocked from the project entirely.

---

## 4. Data model

The schema is **unchanged from the Node design** — SQLite is SQLite regardless of which language opens the file. Only the connection-management section (§4.4) differs, because PHP has no persistent process to hold a pool in.

### 4.1 App DB — `data/app.db`

```sql
CREATE TABLE superusers (
  id            TEXT PRIMARY KEY,              -- uuid
  email         TEXT NOT NULL UNIQUE COLLATE NOCASE,
  password_hash TEXT NOT NULL,
  password_algo TEXT NOT NULL,                 -- 'argon2id' | 'bcrypt' — see §8
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
                  CHECK (state IN ('SETUP','TEST','ACTIVE','CLOSED','REVEALED')),
  db_path         TEXT NOT NULL,               -- projects/<id>/project.db
  shuffle_quizzes INTEGER NOT NULL DEFAULT 0,
  require_pin     INTEGER NOT NULL DEFAULT 0,
  results_stale   INTEGER NOT NULL DEFAULT 0,
  created_at      TEXT NOT NULL,
  updated_at      TEXT NOT NULL,
  closed_at       TEXT,
  revealed_at     TEXT
);

CREATE TABLE app_settings (key TEXT PRIMARY KEY, value TEXT);
-- keys: active_project_id, public_project_id, schema_version

CREATE TABLE rate_limits (                      -- see §8, replaces an in-memory limiter
  bucket_key   TEXT NOT NULL,                   -- e.g. 'login:203.0.113.4'
  window_start INTEGER NOT NULL,                -- unix timestamp, floored to window size
  count        INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket_key, window_start)
);
```

`public_project_id` is what an anonymous visitor at `/` gets. If null, show a picker of non-SETUP projects.

### 4.2 Project DB — `data/projects/<projectId>/project.db`

```sql
CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT);
-- title, description_html, schema_version, results_computed_at

CREATE TABLE quizzes (
  id               TEXT PRIMARY KEY,
  position         INTEGER NOT NULL,
  title            TEXT NOT NULL,
  description_html TEXT NOT NULL DEFAULT '',   -- the story / prompt; text+img+audio+video
  explanation_html TEXT NOT NULL DEFAULT '',   -- shown after wrap-up regardless of choice
  points           INTEGER NOT NULL DEFAULT 1,
  shuffle_options  INTEGER NOT NULL DEFAULT 1,  -- off for naturally ordered options (years, ages)
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
-- Service-layer invariants: exactly 4 options per quiz, exactly 1 with is_correct = 1.

CREATE TABLE users (                            -- participant registry
  id           TEXT PRIMARY KEY,
  name_key     TEXT NOT NULL UNIQUE,           -- normalised: NFKD, diacritics stripped, lowercased
  name_display TEXT NOT NULL,                  -- as typed
  token_hash   TEXT NOT NULL,                  -- sha256 of session token
  pin_hash     TEXT,
  shuffle_seed INTEGER NOT NULL,
  db_path      TEXT NOT NULL,                  -- users/<userId>.db
  created_at   TEXT NOT NULL,
  last_seen_at TEXT
);

CREATE TABLE media (
  id          TEXT PRIMARY KEY,
  filename    TEXT NOT NULL,
  stored_path TEXT NOT NULL,                    -- media/<id>.<ext>, served directly by Apache
  mime        TEXT NOT NULL,
  bytes       INTEGER NOT NULL,
  width INTEGER, height INTEGER, duration_s REAL,
  uploaded_by TEXT,
  created_at  TEXT NOT NULL
);

-- results, fully recomputed on every close
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
  user_id    TEXT NOT NULL,
  quiz_id    TEXT NOT NULL,
  option_id  TEXT,                              -- null = unanswered
  is_correct INTEGER NOT NULL DEFAULT 0,
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
```

### 4.3 Participant DB — `data/projects/<projectId>/users/<userId>.db`

```sql
CREATE TABLE profile (key TEXT PRIMARY KEY, value TEXT);
-- user_id, name_display, created_at, last_seen_at, shuffle_seed

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
  kind TEXT NOT NULL,                            -- join|answer|change|resume
  detail TEXT
);
```

The participant DB stores no correctness — that lives only in the project DB — so a copied participant DB is useless as an answer key.

### 4.4 Connection management (PHP model — replaces the Node connection-pool design)

There is no persistent process, so there is no pool to manage. Each PHP request:

1. Opens `PDO('sqlite:' . $path, ...)` for exactly the DB files it needs (usually the app DB and one project DB; the scoring job additionally attaches every participant DB in turn).
2. Sets `PRAGMA busy_timeout = 5000;` and `PRAGMA foreign_keys = ON;` immediately after opening.
3. **Does not set `journal_mode = WAL`.** Use the default rollback journal (or explicitly `PRAGMA journal_mode = DELETE;`). WAL relies on shared-memory (`-wal`/`-shm`) files and locking semantics that are unreliable on some networked filesystems; the default rollback journal is slower per-write but has none of that risk, and at 25 concurrent participants the difference is not perceptible.
4. Lets the connection close naturally at the end of the request (PHP does this automatically when the `PDO` object goes out of scope).
5. Wraps every write in the smallest possible transaction (`BEGIN IMMEDIATE` → statements → `COMMIT`) to minimise the window in which a lock is held.
6. On `SQLITE_BUSY`, retries up to 3 times with a short sleep (`usleep(50_000)`) before surfacing a `409 CONFLICT` to the client, which the frontend already treats as retryable.

`server/src/Db/Connections.php` centralises steps 1–3 behind three functions: `appDb()`, `projectDb(string $projectId)`, `userDb(string $projectId, string $userId)`. All repository classes call these — no raw `new PDO(...)` anywhere else in the codebase, so if a future move to a VPS makes WAL/pooling worthwhile, it changes in one file.

Migrations: numbered SQL files per DB kind (`migrations/{app,project,user}/NNN_*.sql`), applied on open by comparing against `PRAGMA user_version`.

**Deploy-day smoke test (do this before trusting the system with real data):** from an SSH session on the DreamHost account, run a small PHP script that opens a test SQLite file in the real data directory and fires 20 concurrent writes (via `pcntl_fork` or 20 background `php` CLI invocations) to confirm no corruption and no indefinite lock waits. This is cheap insurance and belongs in Phase 1's "done when."

---

## 5. Randomisation

Deterministic, no stored order tables. Ported to PHP (previously specified as TypeScript, since the shuffle now only ever runs server-side — the frontend never computes it, so there is no cross-language duplication to worry about).

```php
// server/src/Support/Shuffle.php
function seededShuffle(array $items, int $seed, string $salt): array
// mulberry32 PRNG seeded with crc32("$seed:$salt"); Fisher-Yates.
```

- Options within a quiz: `quiz.shuffle_options ? seededShuffle($options, $user->shuffleSeed, $quiz->id) : $options` (ordered by `position`)
- Quizzes (only if `shuffle_quizzes`): `seededShuffle($quizzes, $user->shuffleSeed, 'quizzes')`

Applied server-side before serialising to JSON, so the client never sees authoring order or `is_correct`. Unit-test this PHP implementation against a table of known seed→permutation pairs to make sure it's a faithful port of the algorithm described here (there's no JS version left to compare against at runtime, so the test fixture *is* the spec).

---

## 6. API

Unchanged in shape from the Node version — same routes, same payloads, same status codes. Implemented as **Slim 4** route closures under `server/src/Routes/{Public,Admin}.php`, with PHP-DI for injecting repositories into controllers. JSON throughout. Errors: `{ "error": { "code": ..., "message": ... } }`. Codes: `400 VALIDATION`, `401 UNAUTHENTICATED`, `403 FORBIDDEN`, `404 NOT_FOUND`, `409 NAME_TAKEN`/`CONFLICT`, `413 TOO_LARGE`, `423 LOCKED`.

### 6.1 Participant

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/bootstrap` | project id, title, description_html, state, session presence, display name |
| `POST` | `/api/session/join` | `{ name, pin? }` → create or resume; sets `fq_user` cookie. 423 in SETUP. |
| `POST` | `/api/session/leave` | clears cookie |
| `GET` | `/api/quizzes` | `[{ id, title, answered }]` in the participant's order + `{ answeredCount, total }` |
| `GET` | `/api/quizzes/:quizId` | title, description_html, 4 options in the participant's order, current selection. `is_correct`/`feedback_html` stripped unless REVEALED. |
| `PUT` | `/api/answers/:quizId` | `{ optionId }` → upsert. 423 unless ACTIVE. Returns `{ next, answeredCount, total }` |
| `GET` | `/api/me/results` | 403 unless REVEALED |
| `GET` | `/api/leaderboard` | 403 unless REVEALED. No per-answer detail. |

### 6.2 Superuser

| Method | Path | Notes |
|---|---|---|
| `POST` | `/api/admin/login` | rate-limited 5/15 min/IP via `rate_limits` table (see §8) |
| `POST` | `/api/admin/logout` / `GET /api/admin/me` | |
| `GET/POST/PATCH/DELETE` | `/api/admin/superusers[/:id]` | last-active-admin guard on disable/delete |
| `GET/POST/PATCH/DELETE` | `/api/admin/projects[/:id]` | create makes the directory + project DB; delete moves to `data/_trash/` |
| `POST` | `/api/admin/projects/:id/state` | transition; runs scoring on CLOSED |
| `POST` | `/api/admin/projects/:id/select` | sets active/public project |
| `GET/POST` | `/api/admin/projects/:id/quizzes` | create seeds 4 empty options |
| `GET/PATCH/DELETE` | `/api/admin/quizzes/:quizId` | |
| `POST` | `/api/admin/projects/:id/quizzes/reorder` | |
| `PUT` | `/api/admin/quizzes/:quizId/options` | validates count = 4, exactly one correct |
| `POST/GET/DELETE` | `/api/admin/media` | multipart upload; see §8 for validation |
| `GET/DELETE` | `/api/admin/participants[/:userId]` | |
| `GET` | `/api/admin/results[/users/:userId]` | |
| `POST` | `/api/admin/results/recompute` | file-lock guarded, see §7 |
| `GET` | `/api/admin/export` | ZIP via PHP's `ZipArchive` |
| `GET` | `/api/admin/qrcode` | SVG QR of the participant URL |

**Content-mutation guard:** every mutating `/api/admin/*` route touching quiz content returns `423 { code: 'LOCKED', currentState }` unless the project is in `SETUP` — implemented as a Slim middleware that reads the project state before the route handler runs.

---

## 7. Scoring job (wrap-up)

`server/src/Services/ScoringService.php::computeResults(string $projectId)`. Trivial at this scale (≤ 25 participants × ≤ 100 quizzes = 2,500 rows); keep it synchronous.

**Double-run guard:** since there's no in-process mutex across PHP requests, use an `flock()` lockfile (`data/projects/<id>/.scoring.lock`) — acquire `LOCK_EX` non-blocking at the top of the function, return `409 CONFLICT` if already held, release in a `finally`.

```php
$pdo->exec("ATTACH DATABASE '{$userDbPath}' AS u");

$pdo->prepare("
  INSERT INTO results_answer (user_id, quiz_id, option_id, is_correct, answered_at)
  SELECT :userId, q.id, a.option_id,
         CASE WHEN o.is_correct = 1 THEN 1 ELSE 0 END,
         a.answered_at
  FROM quizzes q
  LEFT JOIN u.answers a ON a.quiz_id = q.id
  LEFT JOIN options  o ON o.id = a.option_id
")->execute(['userId' => $userId]);

$pdo->exec("DETACH DATABASE u");
```

The `LEFT JOIN` from `quizzes` guarantees a row per quiz, so unanswered quizzes and answers pointing at a deleted option both land as `is_correct = 0` rather than erroring. `DETACH` runs in a `finally` block so a failure mid-loop can't leave a database attached to the connection.

Steps 1–6 (roll-up into `results_user`, ranking, `results_option_stats`, `meta.results_computed_at`, `COMMIT`) are otherwise identical to the original design — only the host language changed.

---

## 8. Frontend

**Unchanged from the Node-backed plan.** The React PWA talks to `/api/*` over plain HTTP/JSON; it has no idea whether that's Fastify or Slim on the other end. Reproduced here for completeness — nothing in this section needs re-reading if you've already read rev. 3.

### 8.1 Stack
```
vite, react@18, typescript, react-router-dom@6, @tanstack/react-query,
tailwindcss, @tiptap/react + starter-kit + image + link (admin only),
react-swipeable, framer-motion, zod (client-side form validation only —
see §11 on why validation is now duplicated server-side in PHP),
i18next + react-i18next, vite-plugin-pwa
```

### 8.2 Routes
**Participant:** `/` (landing + join), `/quizzes` (list), `/q/:quizId` (swipeable runner), `/blocked`, `/closed`, `/results`.
**Admin:** `/admin/login`, `/admin/projects`, `/admin/content[/:quizId]`, `/admin/live`, `/admin/results`, `/admin/present`, `/admin/users`.

### 8.3 Quiz runner behaviour
One quiz per screen. Tapping an option submits immediately (`PUT /api/answers/:quizId`), shows a tick, auto-advances to the next quiz after 600 ms. Swipe/arrow keys navigate; skipping is allowed; changing an answer while ACTIVE is allowed. Progress bar with "7 / 24" at top. Summary screen after the last quiz lists unanswered items as jump links. Optimistic UI via React Query with a localStorage outbox for retry on network failure.

### 8.4 HTML content
TipTap toolbar: bold/italic/underline, H2/H3, lists, quote, link, image/audio/video, embed (YouTube/Vimeo → iframe), hr, undo/redo, raw-HTML toggle. Media picker: Upload tab (drag-drop) + Link tab (paste URL). `<RichContent html={...} />` renders sanitised HTML (sanitisation happens server-side on write, per §11), forces `preload="none"` + `controls` on media, lazy-loads images. Nothing autoplays.

### 8.5 Presentation mode (`/admin/present`)
Full-screen, keyboard-driven (`←→` move, `space` reveal, `Esc` exit, `f` fullscreen), large type, high contrast. Per quiz: title+description, four options, reveal-on-space, a bar chart of picks, explanation + correct-option feedback, a collapsible per-participant answer list. Final slide: animated leaderboard.

### 8.6 PWA
`vite-plugin-pwa`, `registerType: 'autoUpdate'`. Manifest with 192/512 px + maskable icons, `apple-touch-icon`, iOS splash screens. Service worker precaches the app shell only; **API responses stay network-only**. Custom install banner + iOS manual-install instructions. `viewport-fit=cover` / safe-area insets for the edge-to-edge swipe UI.

### 8.7 Design notes
Warm and celebratory: serif display face (Fraunces/Playfair) for titles, clean sans for body, generous spacing, ≥48 px tap targets, photo-friendly cards. Light/dark supported. Subtle animation only.

### 8.8 Language (i18n)
**Scope of translation: interface chrome only** — quiz content is superuser-authored HTML served verbatim, no per-language content fields. **Admin UI: English only**, plain string literals, no `t()`. **Participant UI: Polish + English**, both bundles shipped in the main chunk, `i18next`/`react-i18next`, detection order `localStorage.fq_lang` → `navigator.language` → `en` fallback, header toggle writing to localStorage, `<html lang>` updated on switch. Every participant string goes through `t()` from the first commit. Polish needs three plural forms (`one`/`few`/`many`) for strings like "answered N of M" — use i18next's plural keys, not concatenation. API errors are machine codes; the client maps them to translated text, so the PHP server never emits user-facing prose to participants.

---

## 9. Security

- **Passwords — Argon2id with a verified fallback.** PHP's `PASSWORD_ARGON2ID` requires the Argon2 support to be compiled into the PHP build; not every shared-hosting PHP build includes it. At Phase 1, check `defined('PASSWORD_ARGON2ID')` (equivalently, `php -m | grep -i sodium`) on the actual DreamHost PHP version selected for the domain. If present, use it (`memory_cost` 19456 KiB, `time_cost` 4, `threads` 1 — the higher time cost compensates for not being able to tune memory as high as a dedicated box would allow). If absent, fall back to `PASSWORD_BCRYPT` (cost 12) — still a solid, universally-available choice, and store which algorithm was used per-row (`superusers.password_algo`) so a later upgrade can be applied opportunistically at next login without forcing a mass reset.
- **Admin session:** JWT (HS256, 12 h) via `firebase/php-jwt`, in an httpOnly, `SameSite=Lax`, `Secure` cookie. Secret from `config.php` (outside the web root, see §10).
- **Participant session:** 32-byte random token (`random_bytes(32)`), sha256-hashed into `users.token_hash`, httpOnly cookie, 90-day expiry, mirrored to localStorage.
- **CSRF:** `SameSite=Lax` plus an `Origin` header check on every mutating request (a small Slim middleware).
- **HTML sanitisation:** server-side on write with **HTML Purifier** (`ezyang/htmlpurifier`, the mature, well-maintained PHP equivalent of DOMPurify). Configure its allowlist to match the tag/attribute set from the original spec:
  - tags: `p br strong em u s h2 h3 ul ol li blockquote a img audio video source figure figcaption hr span div table thead tbody tr td th`
  - attrs: `href src alt title controls poster width height class` + a narrow `style` allowlist
  - `<iframe>` is **not** in HTML Purifier's default allowlist (by design, for XSS safety) and needs a two-pass approach: before running Purifier, extract every `<iframe src="...">` into a placeholder token and validate its `src` against the allowlist (`youtube.com/embed`, `youtube-nocookie.com/embed`, `player.vimeo.com`, `w.soundcloud.com`); run everything else through Purifier; re-inject only the iframes that passed validation. Write this as `Support/IframeSanitizer.php` with its own unit tests — it's the one piece of sanitisation logic that isn't just "configure the library."
  - all `on*` handlers, `<script>`, `<style>`, `javascript:` URLs stripped by Purifier's default behaviour
  - external `<a>` forced to `target="_blank" rel="noopener noreferrer"` via a Purifier injector or a post-process pass
- **Uploads:** max 50 MB (enforced both in `php.ini`'s `upload_max_filesize`/`post_max_size` for the domain — set via `.user.ini` on DreamHost, since shared hosting has no server-wide `php.ini` access — and again in application code). MIME sniffed via PHP's built-in `finfo` (`fileinfo` extension, standard in PHP 8.3, no external package needed) rather than trusting the client's `Content-Type`. Allowed: `image/jpeg|png|webp|gif|avif`, `audio/mpeg|ogg|wav|mp4`, `video/mp4|webm`. Stored under a generated UUID filename. Images resized to a 1920 px cap and re-encoded (stripping EXIF) with the **GD** extension (universally available on PHP shared hosting; use Imagick instead if `extension_loaded('imagick')` is true, since it produces better quality, but don't depend on it being present).
- **Rate limiting:** no in-memory limiter is available across PHP requests, so this uses the `rate_limits` table in the app DB — a small fixed-window counter (`bucket_key = "{route}:{ip}"`, `window_start` floored to the window size). A short Slim middleware increments and checks the count before the handler runs; a cron job (see §12) prunes rows older than a day. Limits: login 5/15 min/IP, join 10/min/IP, answers 120/min/session, uploads 30/hour/admin.
- **Headers:** CSP, `X-Content-Type-Options`, `X-Frame-Options`, etc. set via a Slim middleware (no `helmet` package available in PHP, but it's a dozen lines) — `img-src`/`media-src 'self' https:'`, `frame-src` limited to the embed allowlist.
- **Names:** trimmed, whitespace collapsed, 2–40 chars, Unicode letters/marks/digits/space/`-'.` only. Uniqueness on `name_key` (NFKD-normalised, diacritics stripped, lowercased via PHP's `Normalizer` class from the `intl` extension — confirm `intl` is enabled for the domain; if not, a manual diacritic-strip table is a small fallback) so "Ania"/"ania"/"Ańia" collide.

---

## 10. Repository layout and deployment

### 10.1 Layout

```
family-quiz/
├── web/                          # built locally, deployed as static files
│   ├── src/... (unchanged from §8)
│   ├── public/{manifest.webmanifest,icons/*}
│   └── vite.config.ts
├── server/
│   ├── composer.json
│   ├── config.php.example        # copied to config.php on the server, outside web root
│   ├── public/                   # THIS directory is the DreamHost "Web Directory" for /api
│   │   └── index.php             # Slim front controller
│   ├── src/
│   │   ├── Db/Connections.php
│   │   ├── migrations/{app,project,user}/*.sql
│   │   ├── Repo/{Superusers,Projects,Quizzes,Options,Users,Answers,Media,Results}.php
│   │   ├── Services/{Auth,State,Scoring,Sanitizer,Media,Export}.php
│   │   ├── Routes/{Public,Admin}.php
│   │   ├── Middleware/{Auth,StateGuard,RateLimit,Cors,Cron}.php
│   │   └── Support/{Shuffle,IframeSanitizer}.php
│   └── tests/ (PHPUnit)
├── scripts/
│   ├── deploy.sh                 # rsync web/dist and server/ to DreamHost
│   └── smoke-test-locking.php    # Phase 1 concurrency check, see §4.4
└── data/                         # NOT under any web-servable directory; git-ignored
    ├── app.db
    └── projects/<projectId>/{project.db, media/, users/<userId>.db}
```

Keep SQL inside `Repo/` classes — no SQL in route handlers.

### 10.2 DreamHost directory structure (this is the part that differs from a VPS)

Under the DreamHost panel, per domain (say `quiz.example.com`), set the **Web Directory** to a `public/` subfolder rather than the account's default. Concretely, on the server:

```
~/quiz.example.com/
  public/               ← Web Directory points here (Apache docroot)
    index.html           built React app (from web/dist)
    assets/...
    media/...            uploaded images/audio/video — Apache serves these directly, no PHP involved
    .htaccess             SPA fallback + /api/* rewrite, see below
  api/
    public/index.php     ← a SECOND Web Directory, on a subdomain: api.quiz.example.com
    src/...
    vendor/
  config.php              JWT secret, DB paths — outside both web directories
~/quiz-data/               SQLite files — sibling to the domain folder, never web-servable
  app.db
  projects/...
```

**Recommendation: put the API on its own subdomain** (`api.quiz.example.com`) rather than trying to rewrite `/api/*` into a PHP front controller under the same docroot as the static SPA. DreamHost's per-domain "Web Directory" setting makes this the path of least resistance — each subdomain gets its own clean docroot, and CORS between the SPA origin and the API origin is one middleware (`Middleware/Cors.php`) rather than fighting `.htaccess` rewrite rules. The SPA calls `https://api.quiz.example.com/...`; cookies are set with `Domain=.quiz.example.com` so they're shared across both subdomains.

`~/quiz.example.com/public/.htaccess` then only needs the SPA fallback:
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.html [L]
```

### 10.3 Deploy process (`scripts/deploy.sh`)

1. Locally: `npm run build` in `web/` → `web/dist/`.
2. Locally: `composer install --no-dev --optimize-autoloader` in `server/` (build `vendor/` locally against PHP 8.3 to match the DreamHost-selected version, then ship it, rather than relying on Composer being runnable identically over SSH — simpler and avoids version-mismatch surprises).
3. `rsync -avz --delete web/dist/ user@quiz.example.com:~/quiz.example.com/public/`
4. `rsync -avz --exclude tests server/ user@quiz.example.com:~/quiz.example.com/api/`
5. SSH in once to confirm `config.php` exists (never overwritten by deploy) and run any pending migrations (`php scripts/migrate.php`).
6. Confirm both domains have Let's Encrypt TLS issued in the panel (a one-time manual step, takes a few minutes the first time — do this days before the event, not the night before).

No Docker, no VPS, no container registry. `docker-compose.yml` and the `Dockerfile` from earlier revisions are removed from this plan entirely.

### 10.4 Config

`server/config.php.example` (copy to `config.php` on the server, chmod 600, never committed):

```php
<?php
return [
    'jwt_secret'          => 'GENERATE_WITH_openssl_rand_-base64_48',
    'data_dir'             => '/home/USERNAME/quiz-data',
    'seed_admin_email'     => 'pawel@kunstman.net',
    'seed_admin_password'  => 'alamakota123',
    'public_base_url'      => 'https://quiz.example.com',
    'max_upload_mb'        => 50,
    'app_env'              => 'production',
];
```

The bootstrap refuses to serve any request in `app_env=production` if `jwt_secret` still contains the placeholder string or is under 32 bytes. **Seeding:** on first boot, if `superusers` is empty, create the seed admin from config. Log a warning on every request while that account still uses the seed password, and show a dismissible banner in the admin UI. Change it once the app is live — it's sitting in a plan document.

---

## 11. Build phases

**Working agreement:** run straight through all phases without stopping for approval between them. Each phase still ends green (`composer test` + `phpstan` for the server, `npm run typecheck && npm run test && npm run build` for the web app) and gets its own commit. Stop only for genuine ambiguity this document doesn't resolve; log small undocumented decisions to `DECISIONS.md` and keep going.

### Phase 1 — Skeleton (1 day — up from ½ day; PHP env setup on a shared account takes longer than `docker compose up`)
Composer project with Slim 4 + PHP-DI, migration runner for all three DB kinds, `Db/Connections.php`, seed admin, `config.php.example`, `.htaccess` for both subdomains, i18next wired up with empty `pl`/`en` bundles and the language toggle. **Run `scripts/smoke-test-locking.php` against a real DreamHost account** to confirm concurrent SQLite writes behave (§4.4) — this is the one assumption in this whole plan that genuinely needs a live check, do it first.
**Done when:** the deployed skeleton serves the built React shell from `quiz.example.com`, `GET https://api.quiz.example.com/health` returns `{"ok":true}`, `data/app.db` holds the seed superuser, and the concurrency smoke test shows no corruption or long lock waits under 20 concurrent writers.

### Phase 2 — Auth and projects (1 day)
Admin login/logout/me, superuser CRUD with last-admin guard, project CRUD, state transitions with the guard middleware, project switcher, admin shell layout.
**Done when:** log in, create two projects, switch the active one, move a project through all four states; content routes return 423 outside SETUP.

### Phase 3 — Content authoring (1.5 days)
Quiz CRUD (auto-seeding 4 empty options), drag-reorder, TipTap editors, correct-answer validation, media upload + picker + library (GD resize, `finfo` MIME check), HTML Purifier + the iframe two-pass sanitiser, `RichContent` renderer, completeness badges.
**Done when:** a quiz with an image, an audio option and a YouTube embed round-trips through save/reload; two-correct or three-option submissions are rejected; an injected `<script>` and an iframe pointing at a non-allowlisted host are both stripped.

### Phase 4 — Participant flow (2 days)
Join/resume with name normalisation, quiz list, swipe runner, tap-to-submit with auto-advance, deterministic shuffling (PHP port), resume across devices, outbox retry, blocked/closed screens with polling.
**Done when:** two browsers with different names see different option orders; answers survive a hard reload; a SETUP switch mid-quiz bounces the participant to `/blocked` within 10 s.

### Phase 5 — Wrap-up and results (1 day)
`ATTACH`-based scoring with the `flock` double-run guard, results tables, admin results + drill-down, participant results, leaderboard, ZIP export, recompute.
**Done when:** closing with 5 seeded participants produces correct scores; changing the correct option and re-closing updates scores correctly; a deleted-option answer scores 0 without erroring; firing the close endpoint twice in parallel doesn't double-run.

### Phase 6 — Presentation mode (½ day)
Projector view, keyboard nav, reveal-on-space, bar charts, collapsible per-participant list, leaderboard slide, QR code.

### Phase 7 — PWA and polish (1 day)
Manifest, icons, service worker, install prompt incl. iOS instructions, safe areas, dark mode, loading/empty states, error boundary, 404, full Polish translation pass.
**Done when:** Lighthouse PWA audit passes, installs on iOS Safari and Android Chrome, and a script diffing `pl.json`/`en.json` finds no missing keys.

### Phase 8 — Hardening and deploy rehearsal (1 day — up from ½; includes a full dry-run deploy)
Rate-limit middleware (SQLite-backed), security headers middleware, structured logging (PHP's `error_log` to a per-domain log DreamHost already provides, or `monolog` if finer control is wanted), a cron job (DreamHost panel → Cron Jobs) running nightly `.dump`-based backups of every SQLite file to a timestamped tarball, restore instructions in the README, a full deploy rehearsal following §10.3 end to end, and a 25-concurrent-participant load check against the *deployed* environment (not just local).

**Estimate: 9–10 working days** (up from 7–8 — the shared-hosting environment setup, the sanitiser's iframe two-pass, and a real deploy rehearsal each add real time that Docker would have hidden).

---

## 12. Test plan

**Unit (PHPUnit, server)**
- `seededShuffle` deterministic per seed+salt, true permutation, and matches the fixed seed→permutation table carried over from the JS spec.
- `IframeSanitizer`: strips a non-allowlisted host, keeps an allowlisted one, survives malformed HTML.
- HTML Purifier config: strips `<script>`, `onerror=`, `javascript:` hrefs.
- Option validation: rejects ≠ 4 options, rejects 0 or 2 correct.
- Scoring: all correct/wrong/partial/zero, deleted-option answer, ties → equal rank.
- State guard: mutation in each of the four states returns the expected status.
- Rate limiter: fixed-window counting is correct across a window boundary.

**Integration (PHPUnit + Slim's test request factory, or Guzzle against a local `php -S` instance)**
- Duplicate-name join (incl. differently-cased/accented) resumes the same participant.
- Answer submission in CLOSED → 423.
- `GET /api/quizzes/:id` never leaks `is_correct`/`feedback_html` outside REVEALED.
- `/api/leaderboard` never returns per-answer detail.
- Scoring leaves no database attached after a mid-loop failure; double-close returns 409.

**E2E (Playwright, against the deployed DreamHost environment, not just local)**
- Happy path: author 3 quizzes → activate → two participants answer → close → results correct → reveal → participants see own results + leaderboard.
- Swipe navigation on mobile viewport with auto-advance.
- Resume after clearing cookies.

**Manual checklist before the event**
- Install on one iPhone, one Android; play every uploaded media type.
- Projector run-through at real resolution/distance.
- 25 simultaneous joins scripted against the live DreamHost URL; check response times and PHP error log for lock timeouts.
- Full backup → delete `data/` → restore → verify.
- Walk the participant flow once in Polish, once in English; check plural forms at N = 1, 2, 5.
- Confirm both subdomains show a valid, non-expiring-soon TLS certificate.

---

## 13. Operational notes for the event

- Author everything in `SETUP`, well ahead. Dry run with two family members **against the real DreamHost deployment**, not just localhost — shared hosting has enough differences (file permissions, PHP config, TLS) that a local-only rehearsal isn't sufficient here.
- Flip to `ACTIVE` when people arrive; share the URL and QR code.
- Watch `/admin/live` for stragglers before wrapping up.
- Flip to `CLOSED`, run `/admin/present` on the projector, reveal each answer with `space`.
- Flip to `REVEALED` at the end.
- Keep a phone tethered as a backup hotspot.
- Keep the DreamHost panel login handy during the event — the state transitions and the presentation mode are the only things that need live intervention, and everything else is a static SPA that will keep working even if you're not near a laptop.

---

## 14. Deliberately out of scope

Teams, timers and countdowns, live real-time sync (WebSockets — not practical on shared hosting without long-polling workarounds anyway), multi-select or free-text answers, translation of quiz content, a Polish admin UI, email of any kind, analytics, Docker/VPS deployment (superseded by this revision).
