#!/usr/bin/env sh
set -eu

SCRIPT_DIRECTORY=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
cd "$SCRIPT_DIRECTORY/.."

test -f .env.docker || {
    echo "Missing .env.docker. Copy .env.docker.example and fill every secret." >&2
    exit 1
}

docker compose --env-file .env.docker config --quiet
docker compose --env-file .env.docker up -d mysql redis
docker compose --env-file .env.docker build --pull app nginx
docker compose --env-file .env.docker --profile maintenance run --rm migrate
docker compose --env-file .env.docker up -d --force-recreate app worker scheduler nginx
docker compose --env-file .env.docker exec -T app php artisan optimize
docker compose --env-file .env.docker ps
