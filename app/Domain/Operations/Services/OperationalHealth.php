<?php

namespace App\Domain\Operations\Services;

use App\Domain\Operations\Models\OperationalMaintenanceRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class OperationalHealth
{
    public const SCHEDULER_HEARTBEAT_KEY = 'pc:health:scheduler-heartbeat';

    public const QUEUE_HEARTBEAT_KEY = 'pc:health:queue-heartbeat';

    private const STARTUP_AT_KEY = 'pc:health:startup-at';

    public function touchScheduler(): void
    {
        Cache::put(self::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String(), now()->addMinutes(10));
    }

    public function touchQueueWorker(): void
    {
        Cache::put(self::QUEUE_HEARTBEAT_KEY, now()->toIso8601String(), now()->addMinutes(10));
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $startupAt = $this->startupAt();
        $scheduler = $this->heartbeat(self::SCHEDULER_HEARTBEAT_KEY, config('operations.health.scheduler_max_age_minutes'), $startupAt);
        $queue = $this->heartbeat(self::QUEUE_HEARTBEAT_KEY, config('operations.health.queue_max_age_minutes'), $startupAt);
        $lastPrune = OperationalMaintenanceRun::query()->where('task', 'operational_data_prune')->where('status', 'succeeded')->latest('finished_at')->first();
        $lastPruneAt = $lastPrune?->getRawOriginal('finished_at');
        $lastPruneFinishedAt = is_string($lastPruneAt) ? CarbonImmutable::parse($lastPruneAt) : null;
        $pruningState = $this->pruningState($lastPruneFinishedAt, $startupAt);
        $failedJobs = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;
        $disk = $this->diskUsage();

        return [
            'scheduler' => $scheduler,
            'queue_worker' => $queue,
            'pruning' => ['state' => $pruningState, 'last_success_at' => $lastPruneFinishedAt?->toIso8601String()],
            'failed_jobs' => ['state' => $failedJobs >= config('operations.health.failed_jobs_warning_count') ? 'attention' : 'operationnel', 'count' => $failedJobs],
            'disk' => $disk,
            'critical' => $disk['state'] === 'critique',
        ];
    }

    /** @return array{state: string, last_at: string|null} */
    private function heartbeat(string $key, int $maxAgeMinutes, CarbonImmutable $startupAt): array
    {
        $value = Cache::get($key);
        $last = is_string($value) ? CarbonImmutable::parse($value) : null;
        $fresh = $last !== null && $last->greaterThan(now()->subMinutes($maxAgeMinutes));

        $withinGrace = $last === null && $startupAt->greaterThan(now()->subMinutes(config('operations.health.startup_grace_minutes')));

        return ['state' => $fresh ? 'operationnel' : ($withinGrace ? 'en_attente' : 'indisponible'), 'last_at' => $last?->toIso8601String()];
    }

    private function startupAt(): CarbonImmutable
    {
        $now = now()->toIso8601String();
        Cache::add(self::STARTUP_AT_KEY, $now, now()->addHours(config('operations.health.pruning_max_age_hours')));
        $stored = Cache::get(self::STARTUP_AT_KEY);

        return is_string($stored) ? CarbonImmutable::parse($stored) : CarbonImmutable::parse($now);
    }

    private function pruningState(?CarbonImmutable $lastPruneFinishedAt, CarbonImmutable $startupAt): string
    {
        if (! config('operations.pruning_enabled')) {
            return 'desactive';
        }
        if ($lastPruneFinishedAt === null) {
            return $startupAt->greaterThan(now()->subHours(config('operations.health.pruning_max_age_hours'))) ? 'en_attente' : 'attention';
        }

        return $lastPruneFinishedAt->greaterThan(now()->subHours(config('operations.health.pruning_max_age_hours'))) ? 'operationnel' : 'attention';
    }

    /** @return array{state: string, used_percent: int, directories: array<string, int>} */
    private function diskUsage(): array
    {
        return Cache::remember('pc:health:disk-usage', now()->addMinute(), fn (): array => $this->collectDiskUsage());
    }

    /** @return array{state: string, used_percent: int, directories: array<string, int>} */
    private function collectDiskUsage(): array
    {
        $total = @disk_total_space(storage_path());
        $free = @disk_free_space(storage_path());
        $usedPercent = 0;
        if (is_float($total)) {
            $usedPercent = (int) round(100 * (1 - ((float) $free / max(1, (float) $total))));
        }
        $state = $usedPercent >= config('operations.health.disk_critical_percent') ? 'critique' : ($usedPercent >= config('operations.health.disk_elevated_percent') ? 'attention_elevee' : ($usedPercent >= config('operations.health.disk_warning_percent') ? 'attention' : 'operationnel'));

        return ['state' => $state, 'used_percent' => $usedPercent, 'directories' => [
            'public_media_bytes' => $this->directorySize(Storage::disk('public')->path('')),
            'temporary_uploads_bytes' => $this->directorySize(Storage::disk('local')->path('product-staging')),
            'logs_bytes' => $this->directorySize(storage_path('logs')),
            'releases_bytes' => $this->directorySize(config('operations.health.release_path')),
            'backups_bytes' => $this->directorySize(config('operations.health.backup_path')),
        ]];
    }

    private function directorySize(?string $path): int
    {
        if (! is_string($path) || ! is_dir($path)) {
            return 0;
        }
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->isFile() ? $file->getSize() : 0;
        }

        return $size;
    }
}
