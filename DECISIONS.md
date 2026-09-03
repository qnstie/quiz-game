# Decisions log

Small undocumented choices made during implementation. Correct here if wrong.

| Date | Decision |
|---|---|
| 2026-09-03 | Quiz content packs: ZIP export/import (`family-quiz-content.json` + `media/`) and JSON import on Content page. Docs: `docs/quiz-import.md`, example `docs/examples/quizzes-import.json`. |
| 2026-08-21 | Production media files live under the SPA docroot `public/media/` (Apache-served); DB paths stay in `~/quiz-data` outside any web root. Locally, media is under `data/media-public` and symlinked/copied by the PHP media route when needed; uploads write to `data/projects/<id>/media/` and are also exposed via a Slim static route in local mode. |
| 2026-08-21 | JWT cookie names: `fq_admin`, `fq_user`. Cookie `Domain` left unset in local; set from `cookie_domain` in config for production subdomain sharing. |
| 2026-08-21 | Health check is `GET /health` (no `/api` prefix), matching Phase 1 acceptance. |
| 2026-08-21 | Scoring reads participant DBs via short-lived PDO instead of `ATTACH`/`DETACH` — PDO SQLite refused `DETACH` ("database u is locked") even outside a wrapping transaction. Semantics unchanged. |
| 2026-08-21 | **Production host:** `kunstman@iad1-shared-b7-31.dreamhost.com`, app path `/home/kunstman/www/familyquiz`. SSH key is registered for this account. |
| 2026-08-21 | Hidden admin magic login: `admin_magic_token` in `config.php`. SPA entry `/admin/enter?t=…` (same-origin cookie); API `GET /api/admin/magic-login?t=…` redirects or `?format=json`. Logs in seed admin (else first active). Invalid/missing token → 404. Not linked in UI. |
| 2026-08-21 | Agent API at `/api/agent/*` for LLM content editing. Auth: Bearer / `X-Agent-Token` / `?t=` using `agent_api_token` or fallback `admin_magic_token`. Mutations require SETUP or TEST. Docs: `docs/agent-api.md`. |
| 2026-08-21 | Hosted as subdirectory under www.kunstman.net: `https://www.kunstman.net/familyquiz/` (and `…/familyquiz-dev/`). Root `.htaccess` maps into `public/`, blocks `config.php` + raw `api/`. SPA built with `VITE_BASE_PATH=/familyquiz/`; Slim `setBasePath` from `url_path_prefix` / `public_base_url`. |
| 2026-08-22 | Project state `TEST`: editable like SETUP and joinable/answerable like ACTIVE. Entering TEST sets public+active project ids. Migration `002_add_test_state.sql`. |
