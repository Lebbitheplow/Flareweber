#!/usr/bin/env bash
set -euo pipefail

APP_DIR=/var/www/flareweber/app
MODULE_SRC=/var/www/flareweber/module/flareweber
MODULE_DEST="$APP_DIR/userfiles/modules/flareweber"
# Pinned release; the module and compiler are verified against this version.
MICROWEBER_VERSION="${MICROWEBER_VERSION:-v2.0.20}"

mkdir -p "$APP_DIR"

# Serialise first boot: any other container sharing app/ (scaled replicas,
# one-off artisan runs) waits here instead of racing the composer install
# and the migrations. Locking the directory itself keeps create-project's
# "directory must be empty" check happy.
exec 9<"$APP_DIR"
flock 9

if [ ! -f "$APP_DIR/artisan" ]; then
  echo "Installing Microweber $MICROWEBER_VERSION into $APP_DIR (first run only)..."
  composer create-project microweber/microweber "$APP_DIR" "$MICROWEBER_VERSION" \
    --no-interaction --prefer-dist --no-progress
fi

if [ ! -f "$APP_DIR/.env" ]; then
  cp /var/www/flareweber/.env.example "$APP_DIR/.env"
fi

if [ -d "$APP_DIR/userfiles/modules" ] && [ ! -e "$MODULE_DEST" ]; then
  ln -s "$MODULE_SRC" "$MODULE_DEST"
fi

cd "$APP_DIR"

# key:generate only rewrites an existing APP_KEY= line, so make sure one is
# there (the example env leaves it out on purpose: a blank APP_KEY in the
# compose environment would shadow the generated one).
if ! grep -q '^APP_KEY=' .env; then
  printf '\nAPP_KEY=\n' >> .env
fi
if ! grep -q '^APP_KEY=.\+' .env; then
  php artisan key:generate --force --no-interaction || true
fi

php artisan migrate --force --no-interaction || true
php artisan storage:link --no-interaction || true

mkdir -p storage bootstrap/cache userfiles
chown -R www-data:www-data storage bootstrap/cache userfiles || true

# Release the boot lock before handing over to the server process.
exec 9<&-

exec "$@"
