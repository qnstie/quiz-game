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

**Agent API (for LLMs / scripts):** same token (or optional `agent_api_token`). See [docs/agent-api.md](docs/agent-api.md). Quick check:

```bash
curl -s -H "Authorization: Bearer YOUR_TOKEN" http://127.0.0.1:8080/api/agent | jq
```

```bash
npm run typecheck && npm run test && npm run build
composer -d server test
php scripts/smoke-test-locking.php
```

## DreamHost deploy

**Live URL:** [https://www.kunstman.net/familyquiz/](https://www.kunstman.net/familyquiz/)  
**Staging:** [https://www.kunstman.net/familyquiz-dev/](https://www.kunstman.net/familyquiz-dev/)

**Production account (confirmed):**
- SSH: `kunstman@iad1-shared-b7-31.dreamhost.com` (key already registered)
- App root: `/home/kunstman/www/familyquiz` (subdirectory of `www.kunstman.net`)

The domain docroot is `/home/kunstman/www`. App-root `.htaccess` serves `public/`, proxies `/api` `/health` `/media` through `gateway.php`, and blocks `config.php` + raw `api/`.

Deploy:

```bash
./scripts/deploy.sh          # production
./scripts/deploy.sh both     # production + staging
```


## Restore

1. Stop traffic (or put project in SETUP).
2. Untar backup into `quiz-data`.
3. Confirm `app.db` and `projects/*/project.db` open with `sqlite3 … 'PRAGMA integrity_check;'`.

## Docs

- Implementation plan: `docs/family-quiz-implementation-plan.md`
- Runtime decisions: `DECISIONS.md`
