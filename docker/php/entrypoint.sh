#!/usr/bin/env sh
set -eu

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required." >&2
    exit 1
fi

mkdir -p storage/app/public storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

exec "$@"
