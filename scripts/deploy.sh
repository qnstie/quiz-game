#!/usr/bin/env bash
set -euo pipefail

# Usage:
#   ./scripts/deploy.sh
# Or override:
#   REMOTE=… SPA_DIR=… API_DIR=… ./scripts/deploy.sh
#
# Production defaults (DreamHost):
#   kunstman@iad1-shared-b7-31.dreamhost.com
#   /home/kunstman/www/familyquiz

REMOTE="${REMOTE:-kunstman@iad1-shared-b7-31.dreamhost.com}"
SPA_DIR="${SPA_DIR:-/home/kunstman/www/familyquiz/public}"
API_DIR="${API_DIR:-/home/kunstman/www/familyquiz/api}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "==> Building web"
(cd "$ROOT/web" && npm run build)

echo "==> Composer install (prod)"
(cd "$ROOT/server" && composer install --no-dev --optimize-autoloader)

echo "==> Rsync SPA"
rsync -avz --delete "$ROOT/web/dist/" "$REMOTE:$SPA_DIR/"

echo "==> Rsync API"
rsync -avz --delete \
  --exclude tests \
  --exclude .phpunit.cache \
  --exclude config.php \
  "$ROOT/server/" "$REMOTE:$API_DIR/"

echo "==> Done. Confirm config.php exists on the server and TLS is issued for both domains."
