#!/usr/bin/env bash
#
# Container entrypoint.
#
# Only the container started with CONTAINER_ROLE=app prepares the project
# (dependencies, .env, app key, migrations). Worker containers (horizon,
# scheduler, reverb) wait for the "ready" marker it writes, so the stack comes
# up with a single `docker compose up` and no race between containers.
set -euo pipefail

ROLE="${CONTAINER_ROLE:-app}"
READY_MARKER="storage/framework/.container-ready"

log() { echo "[entrypoint:${ROLE}] $*"; }

wait_for_database() {
    log "waiting for PostgreSQL at ${DB_HOST:-postgres}:${DB_PORT:-5432}..."
    until pg_isready -h "${DB_HOST:-postgres}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-postgres}" >/dev/null 2>&1; do
        sleep 1
    done
}

prepare_project() {
    rm -f "${READY_MARKER}"

    if [ ! -f vendor/autoload.php ]; then
        log "installing composer dependencies..."
        composer install --prefer-dist --no-interaction --no-progress
    fi

    if [ ! -f .env ]; then
        log "creating .env from .env.example"
        cp .env.example .env
    fi

    if ! grep -qE '^APP_KEY=.+' .env; then
        log "generating application key"
        php artisan key:generate --force --no-interaction
    fi

    wait_for_database

    log "running migrations"
    php artisan migrate --force --no-interaction

    php artisan config:clear >/dev/null

    touch "${READY_MARKER}"
    log "project ready"
}

wait_until_ready() {
    log "waiting for the app container to finish preparing the project..."
    until [ -f "${READY_MARKER}" ]; do
        sleep 1
    done
}

if [ "${ROLE}" = "app" ]; then
    prepare_project
else
    wait_until_ready
fi

exec "$@"
