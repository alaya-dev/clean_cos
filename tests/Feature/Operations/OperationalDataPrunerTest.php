<?php

namespace Tests\Feature\Operations;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\Operations\Models\OperationalMaintenanceRun;
use App\Domain\Operations\Services\OperationalDataPruner;
use App\Domain\Operations\Services\OperationalHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalDataPrunerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_eligible_records_without_changing_them(): void
    {
        $event = $this->event('succeeded', now()->subDays(31));

        $result = app(OperationalDataPruner::class)->handle(true);

        self::assertSame(1, $result['eligible']['meta_succeeded']);
        self::assertSame(0, $result['deleted']['meta_succeeded']);
        self::assertDatabaseHas('meta_events', ['id' => $event->id]);
        self::assertDatabaseCount('operational_maintenance_runs', 0);
    }

    public function test_pruning_removes_only_old_terminal_or_successful_meta_events_in_chunks(): void
    {
        foreach (range(1, 205) as $index) {
            $this->event('succeeded', now()->subDays(31));
        }
        $terminal = $this->event('permanent_failure', now()->subDays(91));
        $recent = $this->event('succeeded', now()->subDays(1));
        $retrying = $this->event('temporary_failure', now()->subDays(120));
        $pending = $this->event('pending', now()->subDays(120));

        $result = app(OperationalDataPruner::class)->handle();

        self::assertSame(205, $result['deleted']['meta_succeeded']);
        self::assertSame(1, $result['deleted']['meta_terminal']);
        self::assertDatabaseMissing('meta_events', ['id' => $terminal->id]);
        self::assertDatabaseHas('meta_events', ['id' => $recent->id]);
        self::assertDatabaseHas('meta_events', ['id' => $retrying->id]);
        self::assertDatabaseHas('meta_events', ['id' => $pending->id]);
        self::assertDatabaseHas('operational_maintenance_runs', ['task' => 'operational_data_prune', 'status' => 'succeeded']);
    }

    public function test_pruning_removes_old_failed_jobs_and_unreferenced_temporary_uploads_only(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('product-staging/old.webp', 'old');
        Storage::disk('local')->put('product-staging/recent.webp', 'recent');
        touch(Storage::disk('local')->path('product-staging/old.webp'), now()->subHours(49)->getTimestamp());
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'safe', 'failed_at' => now()->subDays(46)]);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'safe', 'failed_at' => now()->subDay()]);

        app(OperationalDataPruner::class)->handle();

        Storage::disk('local')->assertMissing('product-staging/old.webp');
        Storage::disk('local')->assertExists('product-staging/recent.webp');
        self::assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_heartbeat_distinguishes_fresh_and_stale_scheduler_state(): void
    {
        $health = app(OperationalHealth::class);
        $health->touchScheduler();
        $health->touchQueueWorker();
        self::assertSame('operationnel', $health->snapshot()['scheduler']['state']);

        Cache::put(OperationalHealth::SCHEDULER_HEARTBEAT_KEY, now()->subMinutes(6)->toIso8601String(), now()->addMinutes(5));
        self::assertSame('indisponible', $health->snapshot()['scheduler']['state']);
        self::assertFalse($health->snapshot()['critical']);
    }

    public function test_fresh_deployment_and_disabled_pruning_are_operationally_pending_not_failed(): void
    {
        Cache::forget(OperationalHealth::SCHEDULER_HEARTBEAT_KEY);
        Cache::forget(OperationalHealth::QUEUE_HEARTBEAT_KEY);
        Cache::forget('pc:health:startup-at');
        config(['operations.pruning_enabled' => false]);

        $snapshot = app(OperationalHealth::class)->snapshot();

        self::assertSame('en_attente', $snapshot['scheduler']['state']);
        self::assertSame('en_attente', $snapshot['queue_worker']['state']);
        self::assertSame('desactive', $snapshot['pruning']['state']);
        self::assertFalse($snapshot['critical']);
    }

    public function test_audit_records_remain_append_only_even_when_old(): void
    {
        $audit = AuditLog::query()->create([
            'action' => 'test.retention',
            'auditable_type' => 'test',
            'auditable_id' => 'old-record',
            'created_at' => now()->subYears(3),
        ]);

        $result = app(OperationalDataPruner::class)->handle();

        self::assertSame(0, $result['deleted']['audit_logs']);
        self::assertDatabaseHas('audit_logs', ['id' => $audit->id]);
    }

    public function test_failed_pruning_execution_records_only_a_sanitized_error_code(): void
    {
        config(['operations.temporary_upload_directories' => [new \stdClass]]);

        try {
            app(OperationalDataPruner::class)->handle();
            self::fail('The invalid temporary directory must fail pruning.');
        } catch (\TypeError) {
            $run = OperationalMaintenanceRun::query()->latest('id')->firstOrFail();
            self::assertSame('failed', $run->status);
            self::assertSame('TypeError', $run->error_code);
            self::assertNull($run->counts);
        }
    }

    public function test_production_pruning_requires_explicit_enablement(): void
    {
        config(['app.env' => 'production', 'operations.pruning_enabled' => false]);

        $this->artisan('maintenance:prune-operational-data')
            ->expectsOutputToContain('OPERATIONS_PRUNING_ENABLED=true')
            ->assertExitCode(1);

        self::assertDatabaseCount('operational_maintenance_runs', 0);
    }

    private function event(string $state, \DateTimeInterface $date): MetaEvent
    {
        $event = MetaEvent::query()->create([
            'event_name' => 'PageView',
            'event_time' => $date,
            'consent_policy_version' => 1,
            'marketing_consent' => true,
            'payload_hash' => hash('sha256', Str::random()),
            'capi_state' => $state,
            'browser_state' => 'attempted',
            'capi_delivered_at' => $state === 'succeeded' ? $date : null,
        ]);
        $event->forceFill(['created_at' => $date, 'updated_at' => $date])->saveQuietly();

        return $event->fresh();
    }
}
