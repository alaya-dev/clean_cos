# Queue worker on managed or shared hosting

Meta delivery is deliberately asynchronous. A successful connection test is
synchronous, but storefront events are stored in the outbox and delivered by
Laravel queue workers. Therefore an active worker is mandatory for normal CAPI
delivery.

Choose exactly one worker mechanism for each environment.

## Persistent worker

For a VPS, use Supervisor or systemd to maintain a persistent worker. It must
consume `meta,critical,default,media,exports`, start after reboot and restart
automatically. Deployments run `php artisan queue:restart`; they do not create
another worker process.

## Shared-hosting worker fallback

Where persistent processes are not available, install exactly one once-per-
minute scheduler entry based on
[`deploy/shared-hosting/ToutDispo-queue-drain.crontab`](../../deploy/shared-hosting/ToutDispo-queue-drain.crontab).

Replace both placeholder paths, then use the hosting control panel to register
the command. The entry calls `scripts/queue-drain-once.sh`, which consumes the
configured queues until empty or for at most 50 seconds. The `flock` lock avoids
overlapping workers.

Do not install this fallback alongside a persistent Supervisor/systemd worker.

## Immediate recovery

If Meta diagnostics show server events as **En attente**, run this once from
the release directory to drain the existing outbox:

```sh
sh scripts/queue-drain-once.sh
```

Then install one of the worker mechanisms above. The Meta connection test alone
does not prove that normal asynchronous storefront events can be delivered.

## Verification

1. Create a storefront PageView, ViewContent and AddToCart event after consent.
2. Confirm the scheduler or worker runs.
3. Refresh Meta diagnostics within one minute.
4. Verify the server status changes from **En attente** to **Accepté par Meta**.
5. Check `/api/health/ready` and the authorized Meta diagnostics page for a
   fresh queue-worker heartbeat.
