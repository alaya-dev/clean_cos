<?php

namespace App\Jobs;

use App\Domain\MetaTracking\Models\MetaEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchMetaEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $eventPublicId)
    {
        $this->onQueue('meta');
    }

    public function handle(): void
    {
        $event = MetaEvent::query()->where('public_id', $this->eventPublicId)->first();
        if (! $event || $event->capi_state !== 'pending') {
            return;
        }

        // The delivery job is deliberately separate from the order transaction.
        SendMetaEventJob::dispatch($event->public_id)->onQueue('meta');
    }
}
