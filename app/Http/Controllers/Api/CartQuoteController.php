<?php

namespace App\Http\Controllers\Api;

use App\Domain\Commerce\Services\CartQuoteService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartQuoteController extends Controller
{
    public function __invoke(Request $request, CartQuoteService $quotes): JsonResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.product_public_id' => ['required', 'ulid'], 'items.*.variant_public_id' => ['nullable', 'ulid'], 'items.*.quantity' => ['required', 'integer', 'between:1,99'], 'promo_code' => ['nullable', 'string', 'max:80']]);

        return ApiResponse::success($quotes->quote($data['items'], $data['promo_code'] ?? null), ['quoted_at' => now()->toIso8601String(), 'request_id' => $request->attributes->get('request_id')]);
    }
}
