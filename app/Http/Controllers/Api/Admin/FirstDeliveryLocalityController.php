<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\FirstDelivery\Services\FirstDeliveryLocalityService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FirstDeliveryLocalityController extends Controller
{
    public function __invoke(Request $request, FirstDeliveryLocalityService $localities): JsonResponse
    {
        $data = $request->validate([
            'governorate' => ['nullable', 'string', 'max:120'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => $localities->options($data['governorate'] ?? null, $data['search'] ?? null)
                ->map(fn ($locality): array => [
                    'locality_id' => $locality->locality_id,
                    'locality_name' => $locality->locality_name,
                    'delegation_name' => $locality->delegation_name,
                    'governorate_name' => $locality->governorate_name,
                ])
                ->values(),
        ]);
    }
}
