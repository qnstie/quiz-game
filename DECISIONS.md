# Decisions log

Small undocumented choices made during implementation. Correct here if wrong.

| Date | Decision |
|---|---|
| 2026-08-21 | Local API served via `php -S` on `:8080`; Vite proxies `/api` and `/health` so the SPA stays same-origin in development. |
| 2026-08-21 | Production media files live under the SPA docroot `public/media/` (Apache-served); DB paths stay in `~/quiz-data` outside any web root. Locally, media is under `data/media-public` and symlinked/copied by the PHP media route when needed; uploads write to `data/projects/<id>/media/` and are also exposed via a Slim static route in local mode. |
| 2026-08-21 | JWT cookie names: `fq_admin`, `fq_user`. Cookie `Domain` left unset in local; set from `cookie_domain` in config for production subdomain sharing. |
| 2026-08-21 | Health check is `GET /health` (no `/api` prefix), matching Phase 1 acceptance. |
| 2026-08-21 | Scoring reads participant DBs via short-lived PDO instead of `ATTACH`/`DETACH` — PDO SQLite refused `DETACH` ("database u is locked") even outside a wrapping transaction. Semantics unchanged. |
| 2026-08-21 | **Production host:** `kunstman@iad1-shared-b7-31.dreamhost.com`, app path `/home/kunstman/www/familyquiz`. SSH key is registered for this account. |
| 2026-08-21 | Hidden admin magic login: `admin_magic_token` in `config.php`. SPA entry `/admin/enter?t=…` (same-origin cookie); API `GET /api/admin/magic-login?t=…` redirects or `?format=json`. Logs in seed admin (else first active). Invalid/missing token → 404. Not linked in UI. |
