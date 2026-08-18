<?php

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Operations\Models\OperationalMaintenanceRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class PruneAuditLogsAction
{
    private const TASK = 'audit_log_prune';

    private const CHUNK_SIZE = 200;

    /** @return array{eligible: int, deleted: int} */
    public function handle(bool $dryRun = false): array
    {
        $cutoff = now()->subDays($this->retentionDays());
        $eligible = $this->eligibleQuery($cutoff)->count();

        if ($dryRun) {
            return ['eligible' => $eligible, 'deleted' => 0];
        }

        $startedAt = now();
        $run = OperationalMaintenanceRun::query()->create([
            'task' => self::TASK,
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        try {
            $deleted = 0;
            $this->eligibleQuery($cutoff)
                ->orderBy('id')
                ->chunkById(self::CHUNK_SIZE, function ($logs) use (&$deleted): void {
                    $deleted += DB::table('audit_logs')->whereIn('id', $logs->pluck('id'))->delete();
                });

            $finishedAt = now();
            $run->update([
                'status' => 'succeeded',
                'finished_at' => $finishedAt,
                'duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
                'counts' => ['eligible' => $eligible, 'deleted' => $deleted, 'retention_days' => $this->retentionDays()],
            ]);

            return ['eligible' => $eligible, 'deleted' => $deleted];
        } catch (Throwable $exception) {
            $finishedAt = now();
            $run->update([
                'status' => 'failed',
                'finished_at' => $finishedAt,
                'duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
                'error_code' => class_basename($exception),
            ]);

            throw $exception;
        }
    }

    /** @return Builder<AuditLog> */
    private function eligibleQuery(\DateTimeInterface $cutoff): Builder
    {
        // The strict comparison preserves rows exactly at the retention cutoff
        // until the next scheduled run.
        return AuditLog::query()->where('created_at', '<', $cutoff);
    }

    private function retentionDays(): int
    {
        return max(1, (int) config('operations.retention.audit_log_days', 730));
    }
}
