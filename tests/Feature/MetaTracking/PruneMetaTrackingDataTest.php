<?php

namespace Tests\Feature\MetaTracking;

use App\Domain\Commerce\Models\Order;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\MetaTracking\Models\MetaEventAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneMetaTrackingDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_prunes_expired_non_purchase_diagnostics_but_preserves_purchase_history(): void
    {
        $expiredSynthetic = $this->event('PageView', true, now()->subDays(31));
        $expiredDiagnostic = $this->event('ViewContent', false, now()->subMonths(14));
        $recentSynthetic = $this->event('Search', true, now()->subDays(29));
        $purchase = $this->event('Purchase', true, now()->subMonths(14));
        $attempt = MetaEventAttempt::query()->create([
            'meta_event_id' => $purchase->id,
            'channel' => 'capi',
            'attempt_number' => 1,
            'outcome' => 'succeeded',
            'attempted_at' => now()->subDays(366),
        ]);

        $this->artisan('meta:prune-retention')->assertExitCode(0);

        $this->assertDatabaseMissing('meta_events', ['id' => $expiredSynthetic->id]);
        $this->assertDatabaseMissing('meta_events', ['id' => $expiredDiagnostic->id]);
        $this->assertDatabaseHas('meta_events', ['id' => $recentSynthetic->id]);
        $this->assertDatabaseHas('meta_events', ['id' => $purchase->id]);
        $this->assertDatabaseMissing('meta_event_attempts', ['id' => $attempt->id]);
    }

    private function event(string $name, bool $synthetic, \DateTimeInterface $createdAt): MetaEvent
    {
        $attributes = [
            'event_name' => $name,
            'event_time' => $createdAt,
            'consent_policy_version' => 1,
            'marketing_consent' => true,
            'is_synthetic' => $synthetic,
            'source_url' => 'https://passion.test/',
            'context_summary' => [],
            'payload_hash' => hash('sha256', Str::uuid()),
            'capi_state' => 'succeeded',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
        if ($name === 'Purchase') {
            $attributes['order_id'] = Order::query()->create([
                'checkout_idempotency_key' => (string) Str::uuid(),
                'checkout_payload_hash' => hash('sha256', Str::random()),
                'status' => 'confirmee',
                'customer_name' => 'Client test',
                'customer_phone' => '20123456',
                'customer_city' => 'Tunis',
                'customer_address' => 'Adresse test',
                'subtotal_millimes' => 40_000,
                'product_discount_millimes' => 0,
                'promo_code_discount_millimes' => 0,
                'shipping_fee_millimes' => 0,
                'total_millimes' => 40_000,
            ])->id;
        }

        $event = MetaEvent::query()->create($attributes);
        $event->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $event;
    }
}
