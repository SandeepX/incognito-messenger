#!/usr/bin/env bash
# Runs once when the app container starts.
# Migrations are handled by the dedicated one-shot `migrate` compose
# service, so app/reverb/webserver only start after the schema is ready.
set -e

# .env may be mounted in; fall back to the example so env vars passed
# by docker-compose (env_file + environment) drive the config.
if [ ! -f .env ]; then
    cp .env.example .env
fi

php artisan config:clear --no-interaction

echo "app container ready"
exec "$@"
