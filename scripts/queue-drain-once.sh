#!/usr/bin/env sh
set -eu

# Shared-hosting fallback for environments without Supervisor or systemd.
# Invoke this script once per minute through the host scheduler. It drains
# queues then exits; flock in the scheduler entry prevents overlapping runs.
SCRIPT_DIRECTORY=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
cd "$SCRIPT_DIRECTORY/.."

QUEUE_DRAIN_MAX_TIME=${QUEUE_DRAIN_MAX_TIME:-50}
# A shared-hosting fallback has one short-lived worker. Prioritize the Meta
# outbox so a backlog of low-value heartbeat jobs cannot delay CAPI delivery.
QUEUE_DRAIN_QUEUES=${QUEUE_DRAIN_QUEUES:-meta,integrations,critical,default,media,exports}

case "$QUEUE_DRAIN_MAX_TIME" in
    ''|*[!0-9]*)
        echo "QUEUE_DRAIN_MAX_TIME must be a positive number of seconds." >&2
        exit 64
        ;;
esac

if [ "$QUEUE_DRAIN_MAX_TIME" -lt 1 ] || [ "$QUEUE_DRAIN_MAX_TIME" -gt 55 ]; then
    echo "QUEUE_DRAIN_MAX_TIME must be between 1 and 55 seconds." >&2
    exit 64
fi

exec php artisan queue:work \
    --stop-when-empty \
    --max-time="$QUEUE_DRAIN_MAX_TIME" \
    --queue="$QUEUE_DRAIN_QUEUES" \
    --sleep=1 \
    --tries=5 \
    --timeout=60
