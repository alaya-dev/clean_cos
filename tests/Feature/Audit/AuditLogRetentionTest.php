<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\Actions\PruneAuditLogsAction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Operations\Models\OperationalMaintenanceRun;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dry_run_reports_only_logs_strictly_older_than_the_configured_retention_window(): void
    {
        $now = Carbon::parse('2026-08-17 03:25:00');
        Carbon::setTestNow($now);
        $old = $this->auditAt($now->copy()->subDays(731));
        $boundary = $this->auditAt($now->copy()->subDays(730));

        $result = app(PruneAuditLogsAction::class)->handle(true);

        self::assertSame(1, $result['eligible']);
        self::assertSame(0, $result['deleted']);
        self::assertDatabaseHas('audit_logs', ['id' => $old->id]);
        self::assertDatabaseHas('audit_logs', ['id' => $boundary->id]);
        self::assertDatabaseCount('operational_maintenance_runs', 0);
    }

    public function test_pruning_deletes_only_expired_audit_logs_in_bounded_chunks(): void
    {
        $now = Carbon::parse('2026-08-17 03:25:00');
        Carbon::setTestNow($now);
        foreach (range(1, 205) as $index) {
            $this->auditAt($now->copy()->subDays(731), 'expired-'.$index);
        }
        $recent = $this->auditAt($now->copy()->subDays(729), 'recent');
        $boundary = $this->auditAt($now->copy()->subDays(730), 'boundary');

        $result = app(PruneAuditLogsAction::class)->handle();

        self::assertSame(205, $result['eligible']);
        self::assertSame(205, $result['deleted']);
        self::assertDatabaseCount('audit_logs', 2);
        self::assertDatabaseHas('audit_logs', ['id' => $recent->id]);
        self::assertDatabaseHas('audit_logs', ['id' => $boundary->id]);
        self::assertDatabaseHas('operational_maintenance_runs', ['task' => 'audit_log_prune', 'status' => 'succeeded']);
        self::assertSame(205, OperationalMaintenanceRun::query()->sole()->counts['deleted']);
    }

    public function test_production_command_requires_explicit_pruning_enablement_but_allows_dry_run(): void
    {
        $now = Carbon::parse('2026-08-17 03:25:00');
        Carbon::setTestNow($now);
        $audit = $this->auditAt($now->copy()->subDays(731));
        config(['app.env' => 'production', 'operations.pruning_enabled' => false]);

        $this->artisan('audit:prune-retention')
            ->expectsOutputToContain('OPERATIONS_PRUNING_ENABLED=true')
            ->assertExitCode(1);
        $this->artisan('audit:prune-retention --dry-run')
            ->expectsOutputToContain('1 éligible(s), 0 supprimé(s)')
            ->assertExitCode(0);

        self::assertDatabaseHas('audit_logs', ['id' => $audit->id]);
        self::assertDatabaseCount('operational_maintenance_runs', 0);
    }

    public function test_the_audit_retention_command_is_scheduled_monthly(): void
    {
        $schedule = file_get_contents(base_path('routes/console.php'));

        self::assertStringContainsString("Schedule::command('audit:prune-retention')", $schedule);
        self::assertStringContainsString("monthlyOn(1, '03:25')", $schedule);
    }

    private function auditAt(Carbon $createdAt, string $suffix = 'record'): AuditLog
    {
        return AuditLog::query()->create([
            'action' => 'order.test_retention',
            'auditable_type' => 'order',
            'auditable_id' => 'audit-'.$suffix,
            'created_at' => $createdAt,
        ]);
    }
}
