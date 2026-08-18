<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\Operations\Services\OperationalHealth;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationalHealthController extends Controller
{
    public function __invoke(OperationalHealth $health): JsonResponse
    {
        $cacheAvailable = true;
        try {
            Cache::get('pc:health:probe');
        } catch (\Throwable) {
            $cacheAvailable = false;
        }
        $failedJobs = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;
        $pending = MetaEvent::query()->whereIn('capi_state', ['pending', 'sending'])->count();
        $temporaryFailures = MetaEvent::query()->where('capi_state', 'temporary_failure')->count();
        $permanentFailures = MetaEvent::query()->where('capi_state', 'permanent_failure')->count();
        $lastDelivery = MetaEvent::query()->where('capi_state', 'succeeded')->latest('capi_delivered_at')->value('capi_delivered_at');
        $operational = $health->snapshot();
        $state = ! $cacheAvailable || $operational['critical'] ? 'indisponible' : ($failedJobs > 0 || $temporaryFailures > 0 || $permanentFailures > 0 || $operational['scheduler']['state'] === 'indisponible' || $operational['queue_worker']['state'] === 'indisponible' || $operational['failed_jobs']['state'] !== 'operationnel' || $operational['pruning']['state'] === 'attention' ? 'attention_requise' : 'operationnel');

        return ApiResponse::success([
            'state' => $state,
            'cache' => $cacheAvailable ? 'operationnel' : 'indisponible',
            'failed_queue_jobs' => $failedJobs,
            'pending_meta_events' => $pending,
            'temporary_meta_failures' => $temporaryFailures,
            'permanent_meta_failures' => $permanentFailures,
            'last_successful_capi_delivery_at' => $lastDelivery?->toIso8601String(),
            'scheduler' => $operational['scheduler'],
            'queue_worker' => $operational['queue_worker'],
            'pruning' => $operational['pruning'],
            'failed_jobs_health' => $operational['failed_jobs'],
            'disk' => $operational['disk'],
        ]);
    }
}
