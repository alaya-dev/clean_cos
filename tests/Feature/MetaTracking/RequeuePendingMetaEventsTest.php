<?php

namespace Tests\Feature\MetaTracking;

use App\Domain\MetaTracking\Actions\RequeuePendingMetaEventsAction;
use App\Domain\MetaTracking\Models\MetaConfiguration;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Jobs\DispatchMetaEventJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RequeuePendingMetaEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requeues_a_stranded_pending_outbox_event_once(): void
    {
        Queue::fake();
        $event = $this->event('pending');

        self::assertSame(1, app(RequeuePendingMetaEventsAction::class)->handle());
        self::assertNotNull($event->fresh()?->dispatch_requested_at);
        Queue::assertPushed(DispatchMetaEventJob::class, fn (DispatchMetaEventJob $job): bool => $job->eventPublicId === $event->public_id);

        self::assertSame(0, app(RequeuePendingMetaEventsAction::class)->handle());
        Queue::assertPushed(DispatchMetaEventJob::class, 1);
    }

    public function test_it_requeues_only_due_retryable_temporary_failures(): void
    {
        Queue::fake();
        $due = $this->event('temporary_failure', now()->subMinute());
        $blocked = $this->event('temporary_failure', now()->subMinute(), 'configuration_invalid');
        $future = $this->event('temporary_failure', now()->addMinute());

        self::assertSame(1, app(RequeuePendingMetaEventsAction::class)->handle());
        Queue::assertPushed(DispatchMetaEventJob::class, fn (DispatchMetaEventJob $job): bool => $job->eventPublicId === $due->public_id);
        self::assertNull($blocked->fresh()?->dispatch_requested_at);
        self::assertNull($future->fresh()?->dispatch_requested_at);
    }

    public function test_the_command_reports_the_number_of_requeued_events(): void
    {
        Queue::fake();
        $this->event('pending');

        $this->artisan('meta:requeue-pending')
            ->expectsOutput('Queued 1 pending Meta events.')
            ->assertSuccessful();
    }

    private function event(string $state, ?\DateTimeInterface $nextRetryAt = null, ?string $error = null): MetaEvent
    {
        $configuration = MetaConfiguration::query()->firstOrCreate(
            ['configuration_version' => 1, 'state' => 'active'],
            ['tracking_enabled' => true, 'pixel_id' => '1234567890', 'capi_access_token_encrypted' => Crypt::encryptString('test-token'), 'activated_at' => now()],
        );

        return MetaEvent::query()->create([
            'event_name' => 'PageView',
            'meta_configuration_id' => $configuration->id,
            'event_time' => now(),
            'consent_policy_version' => 1,
            'marketing_consent' => true,
            'source_url' => 'http://localhost:8000/',
            'context_summary' => ['route_type' => 'home'],
            'payload_hash' => hash('sha256', uniqid('', true)),
            'capi_state' => $state,
            'next_retry_at' => $nextRetryAt,
            'last_error_classification' => $error,
        ]);
    }
}
