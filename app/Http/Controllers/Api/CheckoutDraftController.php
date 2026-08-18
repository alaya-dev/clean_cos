<?php

namespace App\Http\Controllers\Api;

use App\Domain\Commerce\Services\CheckoutDraftService;
use App\Domain\MetaTracking\Services\MarketingConsentService;
use App\Domain\MetaTracking\Services\MetaAttributionContextFactory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutDraftController extends Controller
{
    public function store(Request $request, CheckoutDraftService $drafts, MarketingConsentService $consent, MetaAttributionContextFactory $attribution): JsonResponse
    {
        $data = $this->validated($request);
        $draft = $drafts->upsert($data['token'] ?? null, $data['customer'] ?? [], $data['items'] ?? [], $data['checkout_data'] ?? [], $data['promo_code'] ?? null, $consent->hasCurrentMarketingConsent($request) ? $attribution->capture($request) : null);

        return response()->json(['data' => ['token' => $draft->public_token, 'last_activity_at' => $draft->last_activity_at ? now()->parse((string) $draft->last_activity_at)->toIso8601String() : null]], $data['token'] ?? null ? 200 : 201);
    }

    public function update(Request $request, string $token, CheckoutDraftService $drafts, MarketingConsentService $consent, MetaAttributionContextFactory $attribution): JsonResponse
    {
        abort_unless((bool) preg_match('/^[0-9a-f-]{36}$/i', $token), 404);
        $data = $this->validated($request);
        $draft = $drafts->upsert($token, $data['customer'] ?? [], $data['items'] ?? [], $data['checkout_data'] ?? [], $data['promo_code'] ?? null, $consent->hasCurrentMarketingConsent($request) ? $attribution->capture($request) : null);

        return response()->json(['data' => ['token' => $draft->public_token, 'last_activity_at' => $draft->last_activity_at ? now()->parse((string) $draft->last_activity_at)->toIso8601String() : null]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'token' => ['nullable', 'uuid'],
            'customer' => ['nullable', 'array'],
            'customer.full_name' => ['nullable', 'string', 'max:180'],
            'customer.phone' => ['nullable', 'string', 'max:40'],
            'customer.governorate' => ['nullable', 'string', 'max:80'],
            'customer.city' => ['nullable', 'string', 'max:160'],
            'customer.address' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array', 'max:100'],
            'items.*.product_public_id' => ['required', 'ulid'],
            'items.*.variant_public_id' => ['nullable', 'ulid'],
            'items.*.quantity' => ['required', 'integer', 'between:1,99'],
            'checkout_data' => ['nullable', 'array', 'max:30'],
            'promo_code' => ['nullable', 'string', 'max:80'],
        ]);
    }
}
