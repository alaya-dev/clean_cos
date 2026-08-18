<?php

namespace App\Domain\MetaTracking\Actions;

use App\Domain\MetaTracking\Models\MetaEvent;
use App\Jobs\DispatchMetaEventJob;
use Illuminate\Database\Eloquent\Builder;

class RequeuePendingMetaEventsAction
{
    private const CLAIM_COOLDOWN_MINUTES = 2;

    public function handle(int $limit = 100): int
    {
        $limit = max(1, min($limit, 500));
        $claimed = 0;
        $now = now();
        $claimableBefore = $now->copy()->subMinutes(self::CLAIM_COOLDOWN_MINUTES);

        $this->candidates($now, $claimableBefore)
            ->orderBy('id')
            ->limit($limit)
            ->each(function (MetaEvent $event) use ($now, $claimableBefore, &$claimed): void {
                $updated = MetaEvent::query()
                    ->whereKey($event->id)
                    ->where('capi_state', $event->capi_state)
                    ->where(function (Builder $query) use ($claimableBefore): void {
                        $query->whereNull('dispatch_requested_at')
                            ->orWhere('dispatch_requested_at', '<=', $claimableBefore);
                    })
                    ->update(['dispatch_requested_at' => $now]);

                if ($updated !== 1) {
                    return;
                }

                DispatchMetaEventJob::dispatch($event->public_id)->onQueue('meta');
                $claimed++;
            });

        return $claimed;
    }

    /** @return Builder<MetaEvent> */
    private function candidates(\DateTimeInterface $now, \DateTimeInterface $claimableBefore): Builder
    {
        return MetaEvent::query()
            ->where('is_synthetic', false)
            ->where(function (Builder $query) use ($now): void {
                $query->where('capi_state', 'pending')
                    ->orWhere(function (Builder $temporary) use ($now): void {
                        $temporary->where('capi_state', 'temporary_failure')
                            ->where(function (Builder $classification): void {
                                $classification->whereNull('last_error_classification')
                                    ->orWhereNotIn('last_error_classification', ['configuration_invalid', 'token_decryption_failed']);
                            })
                            ->whereNotNull('next_retry_at')
                            ->where('next_retry_at', '<=', $now);
                    });
            })
            ->where(function (Builder $query) use ($claimableBefore): void {
                $query->whereNull('dispatch_requested_at')
                    ->orWhere('dispatch_requested_at', '<=', $claimableBefore);
            });
    }
}
