<?php

namespace App\Http\Resources\Api\Admin;

use App\Domain\Promotions\Models\PromoCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin PromoCode */
class PromoCodeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'code' => $this->code,
            'discount_percentage' => $this->discount_percentage,
            'usage_limit' => $this->usage_limit,
            'usage_count' => $this->usage_count,
            'minimum_subtotal_millimes' => $this->minimum_subtotal_millimes,
            'starts_at' => $this->starts_at === null ? null : Carbon::parse((string) $this->starts_at)->toIso8601String(),
            'ends_at' => $this->ends_at === null ? null : Carbon::parse((string) $this->ends_at)->toIso8601String(),
            'is_active' => $this->is_active,
            'is_exhausted' => $this->usage_count >= $this->usage_limit,
        ];
    }
}
