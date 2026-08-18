<?php

namespace App\Domain\Operations\Services;

use App\Domain\Catalog\Models\ProductImage;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\MetaTracking\Models\MetaEventAttempt;
use App\Domain\Operations\Models\OperationalMaintenanceRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OperationalDataPruner
{
    private const TASK = 'operational_data_prune';

    /** @return array{eligible: array<string, int>, deleted: array<string, int>} */
    public function handle(bool $dryRun = false): array
    {
        if ($dryRun) {
            $eligible = $this->eligibleCounts();

            return ['eligible' => $eligible, 'deleted' => array_fill_keys(array_keys($eligible), 0)];
        }

        $startedAt = now();
        $run = OperationalMaintenanceRun::query()->create(['task' => self::TASK, 'status' => 'running', 'started_at' => $startedAt]);

        try {
            $eligible = $this->eligibleCounts();
            $deleted = [
                'meta_attempts' => $this->deleteMetaAttempts(),
                'purchase_meta_attempts' => $this->deletePurchaseMetaAttempts(),
                'meta_succeeded' => $this->deleteModels($this->successfulMetaEvents()),
                'meta_terminal' => $this->deleteModels($this->terminalMetaEvents()),
                'failed_jobs' => $this->deleteFailedJobs(),
                'temporary_uploads' => $this->deleteTemporaryUploads(),
                'exports' => 0,
                'audit_logs' => 0,
            ];
            $finishedAt = now();
            $run->update([
                'status' => 'succeeded',
                'finished_at' => $finishedAt,
                'duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
                'counts' => ['eligible' => $eligible, 'deleted' => $deleted],
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

    /** @return array<string, int> */
    public function eligibleCounts(): array
    {
        return [
            'meta_attempts' => $this->metaAttempts()->count(),
            'purchase_meta_attempts' => $this->purchaseMetaAttempts()->count(),
            'meta_succeeded' => $this->successfulMetaEvents()->count(),
            'meta_terminal' => $this->terminalMetaEvents()->count(),
            'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '<', $this->failedJobsCutoff())->count() : 0,
            'temporary_uploads' => $this->temporaryUploadPaths()->count(),
            'exports' => 0,
            // Audit records are append-only and retained for the configured legal/security window.
            'audit_logs' => 0,
        ];
    }

    /** @return Builder<MetaEvent> */
    private function successfulMetaEvents(): Builder
    {
        return MetaEvent::query()
            ->where('capi_state', 'succeeded')
            ->where('event_name', '!=', 'Purchase')
            ->where(function (Builder $query): void {
                $query->where('capi_delivered_at', '<', now()->subDays(config('operations.retention.meta_success_days')))
                    ->orWhere(function (Builder $fallback): void {
                        $fallback->whereNull('capi_delivered_at')->where('created_at', '<', now()->subDays(config('operations.retention.meta_success_days')));
                    });
            });
    }

    /** @return Builder<MetaEvent> */
    private function terminalMetaEvents(): Builder
    {
        return MetaEvent::query()
            ->whereIn('capi_state', ['permanent_failure', 'skipped_no_consent', 'skipped_tracking_disabled', 'skipped_no_active_configuration'])
            ->where('event_name', '!=', 'Purchase')
            ->where('created_at', '<', now()->subDays(config('operations.retention.meta_terminal_days')));
    }

    /** @param Builder<MetaEvent> $query */
    private function deleteModels(Builder $query): int
    {
        $deleted = 0;
        $query->orderBy('id')->chunkById(200, function ($events) use (&$deleted): void {
            MetaEventAttempt::query()->whereIn('meta_event_id', $events->pluck('id'))->delete();
            foreach ($events as $event) {
                $event->delete();
                $deleted++;
            }
        });

        return $deleted;
    }

    private function deleteMetaAttempts(): int
    {
        $deleted = 0;
        $this->metaAttempts()->orderBy('id')->chunkById(200, function ($attempts) use (&$deleted): void {
            $deleted += MetaEventAttempt::query()->whereIn('id', $attempts->pluck('id'))->delete();
        });

        return $deleted;
    }

    private function deletePurchaseMetaAttempts(): int
    {
        $deleted = 0;
        $this->purchaseMetaAttempts()->orderBy('id')->chunkById(200, function ($attempts) use (&$deleted): void {
            $deleted += MetaEventAttempt::query()->whereIn('id', $attempts->pluck('id'))->delete();
        });

        return $deleted;
    }

    /** @return Builder<MetaEventAttempt> */
    private function metaAttempts(): Builder
    {
        return MetaEventAttempt::query()->whereHas('event', fn (Builder $event): Builder => $event->where('event_name', '!=', 'Purchase'))->where(function (Builder $query): void {
            $query->where('outcome', 'succeeded')->where('attempted_at', '<', now()->subDays(config('operations.retention.meta_success_days')))
                ->orWhere(function (Builder $terminal): void {
                    $terminal->whereIn('outcome', ['permanent_failure', 'skipped_no_consent', 'skipped_tracking_disabled', 'skipped_no_active_configuration'])
                        ->where('attempted_at', '<', now()->subDays(config('operations.retention.meta_terminal_days')));
                });
        });
    }

    /** @return Builder<MetaEventAttempt> */
    private function purchaseMetaAttempts(): Builder
    {
        return MetaEventAttempt::query()
            ->whereHas('event', fn (Builder $event): Builder => $event->where('event_name', 'Purchase')->whereIn('capi_state', ['succeeded', 'permanent_failure', 'skipped_no_consent', 'skipped_tracking_disabled', 'skipped_no_active_configuration']))
            ->where('attempted_at', '<', now()->subDays(config('operations.retention.meta_purchase_attempt_days')));
    }

    private function deleteFailedJobs(): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }
        $deleted = 0;
        DB::table('failed_jobs')->where('failed_at', '<', $this->failedJobsCutoff())->orderBy('id')->chunkById(200, function ($jobs) use (&$deleted): void {
            $ids = $jobs->pluck('id')->all();
            $deleted += DB::table('failed_jobs')->whereIn('id', $ids)->delete();
        });

        return $deleted;
    }

    private function deleteTemporaryUploads(): int
    {
        $paths = $this->temporaryUploadPaths();
        $deleted = 0;
        foreach ($paths->chunk(200) as $chunk) {
            $deleted += Storage::disk('local')->delete($chunk->all()) ? $chunk->count() : 0;
        }

        return $deleted;
    }

    /** @return Collection<int, string> */
    private function temporaryUploadPaths(): Collection
    {
        $cutoff = now()->subHours(config('operations.retention.temporary_upload_hours'))->getTimestamp();
        $referenced = ProductImage::query()->whereNotNull('original_path')->pluck('original_path')->flip();
        $paths = collect();
        foreach (config('operations.temporary_upload_directories', []) as $directory) {
            foreach (Storage::disk('local')->allFiles($directory) as $path) {
                if (! $referenced->has($path) && Storage::disk('local')->lastModified($path) < $cutoff) {
                    $paths->push($path);
                }
            }
        }

        return $paths;
    }

    private function failedJobsCutoff(): \DateTimeInterface
    {
        return now()->subDays(config('operations.retention.failed_jobs_days'));
    }
}
