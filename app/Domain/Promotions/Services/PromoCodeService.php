<?php

namespace App\Domain\Promotions\Services;

use App\Domain\Promotions\Exceptions\PromoCodeUnavailable;
use App\Domain\Promotions\Models\PromoCode;
use Illuminate\Support\Carbon;

class PromoCodeService
{
    /** @return array{model: PromoCode, code: string, percentage: int, discount_millimes: int} */
    public function quote(string $code, int $merchandiseSubtotalMillimes, bool $lock = false): array
    {
        $query = PromoCode::query()->where('code', PromoCode::normalize($code));
        $promoCode = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $promoCode || ! $this->isAvailable($promoCode, $merchandiseSubtotalMillimes)) {
            throw new PromoCodeUnavailable;
        }

        return [
            'model' => $promoCode,
            'code' => $promoCode->code,
            'percentage' => $promoCode->discount_percentage,
            'discount_millimes' => intdiv($merchandiseSubtotalMillimes * $promoCode->discount_percentage, 100),
        ];
    }

    public function consume(PromoCode $promoCode): void
    {
        if ($promoCode->usage_count >= $promoCode->usage_limit) {
            throw new PromoCodeUnavailable;
        }
        $promoCode->increment('usage_count');
    }

    private function isAvailable(PromoCode $promoCode, int $subtotal): bool
    {
        $now = now();

        return $promoCode->is_active
            && $promoCode->usage_count < $promoCode->usage_limit
            && ($promoCode->starts_at === null || Carbon::parse((string) $promoCode->starts_at)->lte($now))
            && ($promoCode->ends_at === null || Carbon::parse((string) $promoCode->ends_at)->gte($now))
            && ($promoCode->minimum_subtotal_millimes === null || $subtotal >= $promoCode->minimum_subtotal_millimes);
    }
}
