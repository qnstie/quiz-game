#!/usr/bin/env bash
set -euo pipefail

# Deploy Family Quiz to DreamHost (subdirectory under www.kunstman.net).
#
# Usage:
#   ./scripts/deploy.sh              # production
#   ./scripts/deploy.sh production
#   ./scripts/deploy.sh dev
#   ./scripts/deploy.sh both
#
# Layout (docroot is the domain www/; app lives in a subfolder):
#   $APP_ROOT/              .htaccess → public/, blocks config.php + api/
#   $APP_ROOT/public/       SPA + gateway.php
#   $APP_ROOT/api/          Slim app
#   $APP_ROOT/config.php
#   $DATA_DIR/              SQLite (outside the web tree)

ENV_NAME="${1:-production}"
REMOTE="${REMOTE:-kunstman@iad1-shared-b7-31.dreamhost.com}"
PHP_BIN="${PHP_BIN:-/usr/local/php83/bin/php}"

case "$ENV_NAME" in
  production|prod)
    APP_ROOT="${APP_ROOT:-/home/kunstman/www/familyquiz}"
    DATA_DIR="${DATA_DIR:-/home/kunstman/familyquiz-data}"
    PUBLIC_BASE_URL="${PUBLIC_BASE_URL:-https://www.kunstman.net/familyquiz}"
    URL_PATH_PREFIX="${URL_PATH_PREFIX:-/familyquiz}"
    APP_ENV_VALUE=production
    ;;
  dev|staging)
    APP_ROOT="${APP_ROOT:-/home/kunstman/www/familyquiz-dev}"
    DATA_DIR="${DATA_DIR:-/home/kunstman/familyquiz-dev-data}"
    PUBLIC_BASE_URL="${PUBLIC_BASE_URL:-https://www.kunstman.net/familyquiz-dev}"
    URL_PATH_PREFIX="${URL_PATH_PREFIX:-/familyquiz-dev}"
    APP_ENV_VALUE=local
    ;;
  both)
    "$0" production
    "$0" dev
    exit 0
    ;;
  *)
    echo "Unknown environment: $ENV_NAME (use production|dev|both)" >&2
    exit 1
    ;;
esac

SPA_DIR="$APP_ROOT/public"
API_DIR="$APP_ROOT/api"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# Vite base must end with /
VITE_BASE_PATH="${URL_PATH_PREFIX}/"

echo "==> Environment: $ENV_NAME"
echo "    REMOTE=$REMOTE"
echo "    APP_ROOT=$APP_ROOT"
echo "    DATA_DIR=$DATA_DIR"
echo "    PUBLIC_BASE_URL=$PUBLIC_BASE_URL"
echo "    VITE_BASE_PATH=$VITE_BASE_PATH"

echo "==> Building web (base=$VITE_BASE_PATH)"
(cd "$ROOT/web" && VITE_BASE_PATH="$VITE_BASE_PATH" npm run build)

echo "==> Composer install (prod vendor)"
(cd "$ROOT/server" && composer install --no-dev --optimize-autoloader --quiet)

echo "==> Ensure remote directories"
ssh "$REMOTE" "mkdir -p '$SPA_DIR' '$API_DIR' '$DATA_DIR' && chmod 750 '$DATA_DIR'"

echo "==> Rsync SPA"
rsync -avz --delete "$ROOT/web/dist/" "$REMOTE:$SPA_DIR/"
scp -q "$ROOT/scripts/remote/gateway.php" "$REMOTE:$SPA_DIR/gateway.php"
scp -q "$ROOT/scripts/remote/public.htaccess" "$REMOTE:$SPA_DIR/.htaccess"

echo "==> Install app-root .htaccess (subdirectory front controller)"
REWRITE_BASE="${URL_PATH_PREFIX}/"
sed "s|@@REWRITE_BASE@@|${REWRITE_BASE}|g" "$ROOT/scripts/remote/app-root.htaccess" \
  | ssh "$REMOTE" "cat > '$APP_ROOT/.htaccess'"

echo "==> Rsync API"
rsync -avz --delete \
  --exclude tests \
  --exclude .phpunit.cache \
  --exclude .phpstan.cache \
  --exclude config.php \
  --exclude config.php.example \
  "$ROOT/server/" "$REMOTE:$API_DIR/"

echo "==> Upload bootstrap helper + run seed/migrate"
scp -q "$ROOT/scripts/remote/bootstrap-env.php" "$REMOTE:/tmp/fq-bootstrap-env.php"
ssh "$REMOTE" "$PHP_BIN /tmp/fq-bootstrap-env.php '$APP_ROOT' '$DATA_DIR' '$PUBLIC_BASE_URL' '$APP_ENV_VALUE'"

echo "==> Sync public_base_url / cors / path prefix in existing config"
scp -q "$ROOT/scripts/remote/update-public-url.php" "$REMOTE:/tmp/fq-update-public-url.php"
ssh "$REMOTE" "$PHP_BIN /tmp/fq-update-public-url.php '$APP_ROOT' '$PUBLIC_BASE_URL' '$URL_PATH_PREFIX' '$DATA_DIR'"

echo "==> Done ($ENV_NAME)."
echo "    Open $PUBLIC_BASE_URL/"
echo "    Health: $PUBLIC_BASE_URL/health"
