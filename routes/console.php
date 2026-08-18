<?php

use App\Domain\Checkout\Actions\PruneExpiredCheckoutIdempotencyRecordsAction;
use App\Domain\MetaTracking\Actions\RequeuePendingMetaEventsAction;
use App\Domain\Navex\Actions\SynchronizeNavexShipmentsAction;
use App\Domain\Operations\Services\OperationalHealth;
use App\Jobs\OperationalQueueHeartbeatJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('checkout-idempotency:prune-expired', function () {
    $deleted = app(PruneExpiredCheckoutIdempotencyRecordsAction::class)->handle();
    $this->components->info("Removed {$deleted} expired checkout idempotency records.");
})->purpose('Prune expired checkout idempotency records');

Artisan::command('meta:requeue-pending {--limit=100}', function () {
    $queued = app(RequeuePendingMetaEventsAction::class)->handle((int) $this->option('limit'));
    $this->line("Queued {$queued} pending Meta events.");
})->purpose('Requeue stranded eligible Meta outbox events');

Artisan::command('navex:synchronize {--limit=50} {--include-old}', function () {
    $count = app(SynchronizeNavexShipmentsAction::class)->handle((int) $this->option('limit'), (bool) $this->option('include-old'));
    $this->line("Synchronized {$count} Navex shipments.");
})->purpose('Synchronize active Navex shipments in batches');

Schedule::call(fn () => app(OperationalHealth::class)->touchScheduler())->name('operational:scheduler-heartbeat')->everyMinute()->withoutOverlapping(2)->onOneServer();
Schedule::call(fn () => OperationalQueueHeartbeatJob::dispatch())->name('operational:queue-heartbeat')->everyMinute()->withoutOverlapping(2)->onOneServer();
Schedule::command('meta:requeue-pending')->name('meta:requeue-pending')->everyMinute()->withoutOverlapping(2)->onOneServer();
$navexSyncMinutes = max(1, min(59, (int) config('navex.sync_interval_minutes', 15)));
Schedule::command('navex:synchronize --limit='.max(1, (int) config('navex.sync_batch_size', 50)))
    ->name('navex:synchronize')
    ->cron('*/'.$navexSyncMinutes.' * * * *')
    ->withoutOverlapping($navexSyncMinutes)
    ->onOneServer();
Schedule::command('checkout-idempotency:prune-expired')->dailyAt('03:05')->withoutOverlapping(60)->onOneServer();
Schedule::command('maintenance:prune-operational-data')
    ->dailyAt('03:15')
    ->withoutOverlapping(180)
    ->onOneServer()
    ->onFailure(fn () => report(new RuntimeException('Operational data pruning failed.')));
Schedule::command('audit:prune-retention')
    ->monthlyOn(1, '03:25')
    ->withoutOverlapping(180)
    ->onOneServer()
    ->onFailure(fn () => report(new RuntimeException('Audit log retention pruning failed.')));
