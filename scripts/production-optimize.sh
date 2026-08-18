#!/usr/bin/env sh
set -eu

# Run after the production environment file and built Vite assets are present.
# Resolve the release directory from this script so the caller does not need to
# rely on its current working directory.
SCRIPT_DIRECTORY=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
cd "$SCRIPT_DIRECTORY/.."

# A deployed release must contain the production asset manifest. The app must
# never be switched to cached configuration while it points at a missing build.
test -s public/build/manifest.json
rm -f public/hot

php artisan migrate --force
php artisan optimize:clear
# Rebuild Laravel's production caches (configuration, events, routes and
# compiled views) once, after stale artifacts have been removed.
php artisan optimize

# Ask existing, process-managed workers to finish their current job and reload
# this release. Do not start queue:work here: Supervisor/systemd owns worker
# lifetime, restart policy and the number of worker processes.
php artisan queue:restart

# Interrupt only a long-running schedule:work process. Cron-driven
# schedule:run deployments safely treat this as a no-op.
php artisan schedule:interrupt || true

# Ubuntu hosts can opt into prerequisite verification after deployment. Shared
# hosting and non-systemd environments intentionally skip this host-level step.
if [ "${VERIFY_UBUNTU_OPERATIONS:-0}" = "1" ]; then
    sh "$SCRIPT_DIRECTORY/verify-ubuntu-operations.sh"
fi
