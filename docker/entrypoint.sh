#!/usr/bin/env bash
set -euo pipefail

APP_DIR=/var/www/flareweber/app
MODULE_SRC=/var/www/flareweber/module/flareweber
MODULE_DEST="$APP_DIR/userfiles/modules/flareweber"

if [ ! -f "$APP_DIR/artisan" ]; then
  echo "Installing Microweber into $APP_DIR (first run only)..."
  composer create-project microweber/microweber "$APP_DIR" --no-interaction --prefer-dist
fi

if [ ! -f "$APP_DIR/.env" ]; then
  cp /var/www/flareweber/.env.example "$APP_DIR/.env"
fi

if [ -d "$APP_DIR/userfiles/modules" ] && [ ! -e "$MODULE_DEST" ]; then
  ln -s "$MODULE_SRC" "$MODULE_DEST"
fi

cd "$APP_DIR"

if ! grep -q '^APP_KEY=.\+' .env; then
  php artisan key:generate --force --no-interaction || true
fi

php artisan migrate --force --no-interaction || true
php artisan storage:link --no-interaction || true

chown -R www-data:www-data storage userfiles || true

exec "$@"
