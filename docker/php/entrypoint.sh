#!/bin/sh
set -e

mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache

if [ -z "$APP_KEY" ] && [ ! -f .env ]; then
    echo "APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

if [ "${1}" = "php-fpm" ]; then
    php artisan package:discover --ansi >/dev/null 2>&1 || true

    if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
        php artisan migrate --force
    fi
fi

exec "$@"
