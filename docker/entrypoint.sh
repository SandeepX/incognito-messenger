#!/usr/bin/env bash
set -e

if [ ! -f .env ]; then
    cp .env.example .env
fi

php artisan config:clear --no-interaction

echo "app container ready"
exec "$@"
