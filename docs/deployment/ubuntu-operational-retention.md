# Ubuntu VPS operational retention

This runbook applies to a non-Docker Ubuntu deployment. It does not apply host configuration automatically.

## Retention policy

| Data | Retention | Cleanup rule |
|---|---:|---|
| Successful Meta diagnostics | 30 days | Only non-Purchase `succeeded` events |
| Terminal Meta diagnostics | 90 days | Non-Purchase `permanent_failure` and skipped terminal states only |
| Completed Purchase delivery attempts | 365 days | Sanitized attempt metadata only; no pending/retrying Purchase attempts |
| Retrying, queued and unresolved Meta events | Never automatically removed | Preserved |
| Failed Laravel jobs | 45 days | `failed_at` cutoff |
| Unreferenced product staging uploads | 48 hours | `storage/app/product-staging` only |
| Exports | No artifact currently exists | Order export is streamed |
| Audit/security logs | 730 days | Monthly audit-only prune; only rows strictly older than the cutoff are removed |
| Backups | 7 daily, 4 weekly, 6 monthly | External backup process |
| Releases | Keep current plus rollback release and configured recent releases | Deployment-owned cleanup only |

Permanent media, orders, payments, inventory, order snapshots, Navex data and unresolved Meta data are never removed by retention commands. The monthly audit command deletes only expired rows from `audit_logs`.

Purchase `meta_events` remain permanently because their stable `event_id`, event name, final CAPI state and safe context summary support order audit and deduplication. The order and order-item snapshots remain the authoritative commercial audit record. Only completed Purchase attempt rows may be pruned after 365 days; pending, sending and retrying Purchase delivery diagnostics are preserved indefinitely.

## Health severity

The public readiness endpoint fails only for database, required cache/Redis, or critical disk failures. Scheduler and queue heartbeats are restricted operational signals: stale values are warnings for administrators, not automatic storefront readiness failures. Disk usage is `attention` at 70%, `attention_elevee` at 80%, and `critique` at 90%. When pruning is disabled it is reported as `desactive`, not stale. Fresh deployments have a ten-minute heartbeat grace period and a first-pruning grace period equal to the configured pruning window.

## Scheduler and workers

Enable Ubuntu's standard cron service and install exactly one deploy-user
crontab entry:

```cron
* * * * * cd /var/www/ToutDispo/current && /usr/bin/php artisan schedule:run --no-interaction >> /var/log/passion/scheduler.log 2>&1
```

Use Ubuntu's standard `cron` service; no project-specific cron systemd unit or
crontab template is required. The verification script rejects duplicate
matching entries and an enabled dedicated scheduler systemd unit.

The worker service must be Supervisor-managed with `autostart=true`, `autorestart=true`, and queues including `critical,meta,default,media,exports`. Environments without persistent processes may instead use the mutually exclusive once-per-minute shared-hosting worker fallback documented in [`queue-worker-hosting.md`](queue-worker-hosting.md).

Set `OPERATIONS_PRUNING_ENABLED=true` only after the dry-run evidence and backup policy have been reviewed. A destructive production run is refused otherwise. The daily operational pruner handles Meta/jobs/uploads; the separate `audit:prune-retention` command runs on the first day of each month at 03:25 and uses `OPERATIONS_AUDIT_LOG_RETENTION_DAYS` (default `730`).

## Log rotation

```bash
sudo install -m 0644 deploy/ubuntu/ToutDispo-logrotate.conf /etc/logrotate.d/ToutDispo
sudo logrotate --debug /etc/logrotate.d/ToutDispo
sudo logrotate -f /etc/logrotate.d/ToutDispo
```

Laravel Daily exclusively rotates `storage/logs/laravel-*.log` for 21 days. Logrotate only rotates Nginx, Supervisor, scheduler, deployment and backup logs under `/var/log`; it never targets Laravel application log files.

## Releases and backups

`scripts/prune-ubuntu-retained-artifacts.sh` is a host-level helper and defaults to a dry run. It only operates on an explicit releases directory and backup-tier directories. It never touches `storage`, product media, or database records.

```bash
APP_ROOT=/var/www/passion BACKUPS_ROOT=/var/backups/passion scripts/prune-ubuntu-retained-artifacts.sh --dry-run
APP_ROOT=/var/www/passion BACKUPS_ROOT=/var/backups/passion scripts/prune-ubuntu-retained-artifacts.sh --apply
```

It retains the five newest releases plus the `current` and `previous` rollback targets. It removes backup files only from separately managed `daily`, `weekly`, and `monthly` directories after 7, 28, and 186 days respectively. Review the dry-run output before `--apply`.

## Deployment verification

`scripts/verify-ubuntu-operations.sh` only verifies prerequisites. It never recreates invalid cron, Supervisor or logrotate configuration.

After deploying, run:

```bash
php artisan schedule:run
php artisan maintenance:prune-operational-data --dry-run
php artisan audit:prune-retention --dry-run
curl --fail --silent --show-error http://127.0.0.1/api/health/ready
```

Alternatively run the existing deployment optimization with `VERIFY_UBUNTU_OPERATIONS=1`; it invokes the same verification helper only on a prepared Ubuntu host.

## Manual VM verification still required

- Verify cron and Supervisor survive a reboot.
- Stop/restart cron and Supervisor, then confirm heartbeat recovery.
- Force logrotate and inspect compressed files/ownership.
- Simulate stale scheduler/queue heartbeats and disk thresholds in staging only.
- Verify a failed pruning run records a sanitized error code.
- Confirm backup and release retention outside the application.

## Not performed by this change

No VPS service was installed, rebooted or reconfigured. No backup, release, production Meta dataset, Converty configuration or active advertising campaign was modified.
