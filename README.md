# Family Quiz

Installable PWA + admin for a family evening quiz. React SPA + PHP 8.3 (Slim 4) API, SQLite files, designed for DreamHost Shared hosting.

## Local development

Requirements: Node 20+, PHP 8.3+ with `pdo_sqlite`, `gd`, `intl`, Composer.

```bash
# API
cp server/config.php.example server/config.php
# edit data_dir / jwt_secret if needed
composer -d server install
npm run dev:api          # php -S :8080

# Web (separate terminal)
npm install
npm run dev              # Vite :5173, proxies /api to :8080
```

Admin seed (from config): see `seed_admin_email` / `seed_admin_password`. Change after first login.

**Hidden automation login** (not shown in the UI): set a long `admin_magic_token` in `server/config.php`, then open:

`http://localhost:5173/admin/enter?t=YOUR_TOKEN`

That signs you in as the seed admin (or the first active superuser) and lands on Projects.

```bash
npm run typecheck && npm run test && npm run build
composer -d server test
php scripts/smoke-test-locking.php
```

## DreamHost deploy

**Production account (confirmed):**
- SSH: `kunstman@iad1-shared-b7-31.dreamhost.com` (key already registered)
- App root: `/home/kunstman/www/familyquiz`

1. Create two domains/subdomains (e.g. `quiz.example.com` + `api.quiz.example.com`).
2. Point SPA Web Directory to a `public/` under the app root (or as arranged in the panel).
3. Point API Web Directory to `…/api/public` under the same tree.
4. Keep SQLite data **outside** any web root (e.g. `/home/kunstman/quiz-data`). Copy `server/config.php.example` → `config.php` on the server (chmod 600) and set paths/secrets. `App::create()` loads `server/config.php` relative to the API tree.
5. Select PHP 8.3 for the API domain. Confirm `intl`, `gd`, `pdo_sqlite`, `fileinfo`.
6. Issue Let's Encrypt for both hosts.
7. Deploy:

```bash
REMOTE=kunstman@iad1-shared-b7-31.dreamhost.com \
SPA_DIR=/home/kunstman/www/familyquiz/public \
API_DIR=/home/kunstman/www/familyquiz/api \
./scripts/deploy.sh
```

Adjust `SPA_DIR` / `API_DIR` if the panel Web Directory layout differs from the plan’s subdomain split.

8. Run `php scripts/smoke-test-locking.php /home/kunstman/quiz-data/_smoke` on the server once.

Cron (panel): nightly backup of `quiz-data` using `sqlite3 file '.backup …'` per DB, then tar.

## Restore

1. Stop traffic (or put project in SETUP).
2. Untar backup into `quiz-data`.
3. Confirm `app.db` and `projects/*/project.db` open with `sqlite3 … 'PRAGMA integrity_check;'`.

## Docs

- Implementation plan: `docs/family-quiz-implementation-plan.md`
- Runtime decisions: `DECISIONS.md`
