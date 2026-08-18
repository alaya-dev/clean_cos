<?php

namespace App\Http\Controllers;

use App\Domain\Operations\Services\OperationalHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(OperationalHealth $health): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            Cache::get('health:ready');
            if ($health->snapshot()['critical']) {
                return response()->json(['status' => 'unavailable'], 503);
            }
        } catch (\Throwable) {
            return response()->json(['status' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ready']);
    }
}
