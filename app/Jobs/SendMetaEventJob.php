<?php

namespace App\Jobs;

use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\MetaTracking\Services\MetaConversionsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class SendMetaEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly string $eventPublicId)
    {
        $this->onQueue('meta');
    }

    public function handle(MetaConversionsClient $client): void
    {
        $lock = Cache::lock('pc:meta:event:'.$this->eventPublicId, 60);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }

        try {
            $event = MetaEvent::query()->where('public_id', $this->eventPublicId)->first();
            if (! $event || in_array($event->capi_state, ['succeeded', 'permanent_failure'], true)) {
                return;
            }

            $event->update(['capi_state' => 'sending']);
            $result = $client->send($event->fresh(['configuration', 'order']) ?? $event);
            $attempt = $event->retry_count + 1;
            $configurationBlocked = in_array($result->classification, ['configuration_invalid', 'token_decryption_failed'], true);
            $event->attempts()->create([
                'channel' => 'capi',
                'attempt_number' => $attempt,
                'outcome' => $result->accepted ? 'accepted' : ($configurationBlocked ? 'configuration_error' : ($result->temporary ? 'temporary_failure' : 'permanent_failure')),
                'request_sent' => $result->requestSent,
                'http_status' => $result->httpStatus,
                'events_received' => $result->eventsReceived,
                'error_classification' => $result->accepted ? null : $result->classification,
                'meta_error_code' => $result->metaErrorCode,
                'meta_error_subcode' => $result->metaErrorSubcode,
                'safe_message' => $result->metaMessage,
                'fbtrace_id' => $result->fbtraceId,
                'graph_api_version' => $result->graphApiVersion,
                'attempted_at' => now(),
            ]);
            if ($result->accepted) {
                $event->update(['capi_state' => 'succeeded', 'capi_delivered_at' => now(), 'last_error_classification' => null]);

                return;
            }
            if ($configurationBlocked) {
                $event->update(['capi_state' => 'temporary_failure', 'retry_count' => $attempt, 'next_retry_at' => null, 'last_error_classification' => $result->classification]);

                return;
            }
            if (! $result->temporary || $attempt >= $this->tries) {
                $event->update(['capi_state' => 'permanent_failure', 'retry_count' => $attempt, 'last_error_classification' => $result->classification]);

                return;
            }
            $baseDelay = $result->retryAfterSeconds ?? min(3600, 60 * (2 ** ($attempt - 1)));
            $delay = min(3600, $baseDelay + random_int(0, min(30, max(1, intdiv($baseDelay, 5)))));
            $event->update(['capi_state' => 'temporary_failure', 'retry_count' => $attempt, 'next_retry_at' => now()->addSeconds($delay), 'last_error_classification' => $result->classification]);
            $this->release($delay);
        } finally {
            $lock->release();
        }
    }
}
