<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Promotions\Models\PromoCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\SavePromoCodeRequest;
use App\Http\Resources\Api\Admin\PromoCodeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PromoCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'usage_state' => ['nullable', 'in:available,exhausted'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $promoCodes = PromoCode::query()
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('code', 'like', '%'.PromoCode::normalize($search).'%'))
            ->when(array_key_exists('is_active', $filters), fn ($query) => $query->where('is_active', $filters['is_active']))
            ->when(($filters['usage_state'] ?? null) === 'exhausted', fn ($query) => $query->whereColumn('usage_count', '>=', 'usage_limit'))
            ->when(($filters['usage_state'] ?? null) === 'available', fn ($query) => $query->whereColumn('usage_count', '<', 'usage_limit'))
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json(['data' => PromoCodeResource::collection($promoCodes)->response()->getData(true)]);
    }

    public function store(SavePromoCodeRequest $request, RecordAuditEventAction $audit): JsonResponse
    {
        $promoCode = PromoCode::query()->create($request->validated());
        $audit->handle('promotions.promo_created', $promoCode, $request->user(), after: ['code' => $promoCode->code]);

        return response()->json(['data' => new PromoCodeResource($promoCode)], 201);
    }

    public function update(SavePromoCodeRequest $request, PromoCode $promoCode, RecordAuditEventAction $audit): JsonResponse
    {
        $before = $promoCode->only(['code', 'discount_percentage', 'usage_limit', 'is_active']);
        $promoCode->update($request->validated());
        $audit->handle('promotions.promo_updated', $promoCode, $request->user(), $before, $promoCode->only(array_keys($before)));

        return response()->json(['data' => new PromoCodeResource($promoCode->fresh())]);
    }

    public function status(Request $request, PromoCode $promoCode, RecordAuditEventAction $audit): JsonResponse
    {
        $payload = $request->validate(['is_active' => ['required', 'boolean']]);
        $before = $promoCode->is_active;
        $promoCode->update(['is_active' => $payload['is_active']]);
        $audit->handle($payload['is_active'] ? 'promotions.promo_activated' : 'promotions.promo_deactivated', $promoCode, $request->user(), ['is_active' => $before], ['is_active' => $payload['is_active']]);

        return response()->json(['data' => new PromoCodeResource($promoCode)]);
    }

    public function destroy(Request $request, PromoCode $promoCode, RecordAuditEventAction $audit): JsonResponse
    {
        $before = $promoCode->only(['public_id', 'code', 'usage_count', 'is_active']);

        DB::transaction(function () use ($promoCode, $audit, $request, $before): void {
            $promoCode->delete();
            $audit->handle('promotions.promo_deleted', $promoCode, $request->user(), before: $before);
        });

        return response()->json(['data' => null]);
    }
}
