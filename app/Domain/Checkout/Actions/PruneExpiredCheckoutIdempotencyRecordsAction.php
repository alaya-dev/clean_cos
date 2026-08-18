<?php

namespace App\Domain\Checkout\Actions;

use App\Domain\Checkout\Models\CheckoutIdempotencyRecord;

class PruneExpiredCheckoutIdempotencyRecordsAction
{
    public function handle(): int
    {
        return CheckoutIdempotencyRecord::query()->where('expires_at', '<=', now())->delete();
    }
}
