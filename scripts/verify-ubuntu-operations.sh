#!/usr/bin/env sh
set -eu

APP_PATH=${1:-/var/www/ToutDispo/current}
DEPLOY_USER=${DEPLOY_USER:-deploy}
CRON_LINE="* * * * * cd ${APP_PATH} && /usr/bin/php artisan schedule:run --no-interaction >> /var/log/passion/scheduler.log 2>&1"

systemctl is-enabled cron >/dev/null
systemctl is-active cron >/dev/null
test "$(crontab -u "$DEPLOY_USER" -l 2>/dev/null | grep -Fxc "$CRON_LINE")" -eq 1
test "$( (crontab -u "$DEPLOY_USER" -l 2>/dev/null; grep -Rhs --include='*' 'artisan schedule:run' /etc/crontab /etc/cron.d 2>/dev/null || true) | grep -F "$APP_PATH" | wc -l | tr -d ' ' )" -eq 1
! systemctl is-enabled ToutDispo-scheduler.service >/dev/null 2>&1
! systemctl is-active ToutDispo-scheduler.service >/dev/null 2>&1
logrotate --debug /etc/logrotate.d/ToutDispo >/dev/null
supervisorctl status | grep -Eq 'RUNNING.*(critical|meta|default)'
test -w "${APP_PATH}/storage"
df -P "${APP_PATH}" | awk 'NR == 2 { exit ($5 + 0 >= 90) }'
cd "$APP_PATH"
php artisan schedule:run --no-interaction
php artisan tinker --execute="exit(app(\\App\\Domain\\Operations\\Services\\OperationalHealth::class)->snapshot()['scheduler']['state'] === 'operationnel' ? 0 : 1);"
php artisan maintenance:prune-operational-data --dry-run
