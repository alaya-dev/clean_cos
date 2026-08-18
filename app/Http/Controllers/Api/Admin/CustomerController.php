<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Commerce\Services\CustomerHistoryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function lookup(Request $request, CustomerHistoryService $customers): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:40']]);
        $customer = $customers->findByPhone($data['phone']);

        return response()->json(['data' => $customer ? [
            'phone' => $customer->phone,
            'phone_normalized' => $customer->phone_normalized,
            'full_name' => $customer->name,
            'governorate' => $customer->governorate,
            'city' => $customer->city,
            'address' => $customer->address,
            'last_order_at' => $customer->last_order_at ? now()->parse((string) $customer->last_order_at)->toIso8601String() : null,
            'orders_count' => $customer->orders_count,
        ] : null]);
    }
}
