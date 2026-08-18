<?php

namespace Tests\Feature\StoreManagement;

use App\Domain\Commerce\Models\Order;
use App\Domain\Complaints\Models\Complaint;
use App\Models\User;
use App\Support\Observability\SentryEventSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Sentry\Event;
use Sentry\UserDataBag;
use Tests\TestCase;

class ComplaintWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['127.0.0.1', '198.51.100.10', '198.51.100.11', '198.51.100.12', '198.51.100.13', '198.51.100.14', '198.51.100.20', '198.51.100.21', '198.51.100.22', '198.51.100.23', '198.51.100.77'] as $ip) {
            RateLimiter::clear(md5('complaints'.'ip:'.$ip));
        }
        foreach (['', '55123456', '55123457', '99111111', '66111111', '66111112', '66111113'] as $phone) {
            $digest = hash_hmac('sha256', $phone, (string) config('app.key'));
            RateLimiter::clear(md5('complaints'.'phone:'.$digest));
        }
    }

    public function test_public_validation_consent_honeypot_and_unknown_orders_do_not_leak(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->postJson('/api/v1/public/complaints', [])->assertUnprocessable()->assertJsonPath('message', 'La demande est invalide.');
        $withoutConsent = $this->payload();
        unset($withoutConsent['consent']);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])->postJson('/api/v1/public/complaints', $withoutConsent)->assertUnprocessable();

        $honeypot = array_replace($this->payload(), ['website' => 'robot']);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.12'])->postJson('/api/v1/public/complaints', $honeypot)->assertCreated()->assertJsonPath('message', 'Votre réclamation a été envoyée.');
        $this->assertSame(0, Complaint::query()->count());

        $unknown = $this->payload() + ['order_reference' => (string) str()->ulid()];
        $unknownResponse = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.13'])->postJson('/api/v1/public/complaints', $unknown)->assertCreated();
        $normalResponse = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.14'])->postJson('/api/v1/public/complaints', $this->payload(['customer_phone' => '55123457']))->assertCreated();
        $this->assertSame($unknownResponse->json('message'), $normalResponse->json('message'));
        $this->assertNull(Complaint::query()->where('customer_phone', '55123456')->firstOrFail()->order_id);
    }

    public function test_public_rate_limit_uses_a_generic_french_response(): void
    {
        $payload = $this->payload(['customer_phone' => '99111111']);
        foreach (range(1, 3) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])->postJson('/api/v1/public/complaints', $payload)->assertCreated();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])->postJson('/api/v1/public/complaints', $payload)
            ->assertTooManyRequests()->assertJsonPath('message', 'Trop de requêtes. Réessayez plus tard.');
    }

    public function test_complaint_images_are_validated_reencoded_and_kept_private(): void
    {
        Storage::fake('local');
        $valid = $this->payload() + ['attachment' => UploadedFile::fake()->image('preuve.jpg', 1200, 900)];
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->post('/api/v1/public/complaints', $valid, ['Accept' => 'application/json'])->assertCreated();
        $complaint = Complaint::query()->firstOrFail();
        $this->assertSame('image/webp', $complaint->attachment_mime);
        Storage::disk('local')->assertExists($complaint->attachment_path);
        $this->get('/api/v1/admin/complaints/'.$complaint->public_reference.'/attachment')->assertUnauthorized();

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin, 'sanctum')->get('/api/v1/admin/complaints/'.$complaint->public_reference.'/attachment')->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $wrongMime = $this->payload(['customer_phone' => '66111111']) + ['attachment' => UploadedFile::fake()->create('preuve.jpg', 20, 'text/plain')];
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21'])->post('/api/v1/public/complaints', $wrongMime, ['Accept' => 'application/json'])->assertUnprocessable();
        $oversized = $this->payload(['customer_phone' => '66111112']) + ['attachment' => UploadedFile::fake()->create('preuve.jpg', 5_121, 'image/jpeg')];
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.22'])->post('/api/v1/public/complaints', $oversized, ['Accept' => 'application/json'])->assertUnprocessable();
        $dimensions = $this->payload(['customer_phone' => '66111113']) + ['attachment' => UploadedFile::fake()->image('large.png', 8_001, 10)];
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->post('/api/v1/public/complaints', $dimensions, ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_admin_and_super_admin_can_manage_timeline_notes_and_status_without_manual_order_linking(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $order = Order::query()->create($this->orderAttributes());
        $this->postJson('/api/v1/public/complaints', $this->payload(['order_reference' => $order->public_reference]))->assertCreated();
        $complaint = Complaint::query()->firstOrFail();
        $this->assertSame($order->id, $complaint->order_id);

        $this->getJson('/api/v1/admin/complaints')->assertUnauthorized();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/complaints?status=nouvelle')->assertOk();
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/admin/complaints/'.$complaint->public_reference)->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/complaints/'.$complaint->public_reference.'/notes', ['body' => 'Vérification interne uniquement.'])->assertCreated();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/complaints/'.$complaint->public_reference.'/transitions', ['to_status' => 'en_cours'])->assertOk();
        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/admin/complaints/'.$complaint->public_reference.'/transitions', ['to_status' => 'resolue'])->assertOk();
        $hasManualLinkRoute = collect(Route::getRoutes()->getRoutes())->contains(
            fn ($route): bool => in_array('PATCH', $route->methods(), true)
                && $route->uri() === 'api/v1/admin/complaints/{complaint}'
        );
        $this->assertFalse($hasManualLinkRoute);

        $this->assertDatabaseHas('complaint_notes', ['complaint_id' => $complaint->id, 'body' => 'Vérification interne uniquement.']);
        $this->assertDatabaseHas('complaint_status_history', ['complaint_id' => $complaint->id, 'to_status' => 'resolue']);
        $this->assertSame($order->id, $complaint->fresh()->order_id);
        $this->assertSame('resolue', $complaint->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'complaints.status_transitioned']);
    }

    public function test_authorized_admin_can_soft_delete_a_complaint_without_deleting_its_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->postJson('/api/v1/public/complaints', $this->payload())->assertCreated();
        $complaint = Complaint::query()->firstOrFail();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/complaints/'.$complaint->public_reference.'/notes', ['body' => 'Note conservée.'])->assertCreated();

        $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/admin/complaints/'.$complaint->public_reference)->assertOk();
        $deleted = Complaint::withTrashed()->findOrFail($complaint->id);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertDatabaseHas('complaint_notes', ['complaint_id' => $complaint->id, 'body' => 'Note conservée.']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/complaints')->assertOk()->assertJsonMissing(['public_reference' => $complaint->public_reference]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'complaints.deleted']);
    }

    public function test_unauthenticated_user_cannot_delete_a_complaint(): void
    {
        $this->postJson('/api/v1/public/complaints', $this->payload())->assertCreated();
        $complaint = Complaint::query()->firstOrFail();
        $this->deleteJson('/api/v1/admin/complaints/'.$complaint->public_reference)->assertUnauthorized();
        $this->assertNull(Complaint::withTrashed()->findOrFail($complaint->id)->deleted_at);
    }

    public function test_xss_is_stored_as_text_and_sentry_event_context_contains_no_complaint_pii(): void
    {
        $payload = $this->payload(['description' => '<script>alert(1)</script><img src=x onerror=alert(2)>']);
        $this->postJson('/api/v1/public/complaints', $payload)->assertCreated();
        $complaint = Complaint::query()->firstOrFail();
        $this->assertSame($payload['description'], $complaint->description);

        $event = Event::createEvent();
        $event->setRequest(['data' => $payload]);
        $event->setExtra(['complaint' => $payload]);
        $event->setUser(new UserDataBag('complaint-user', 'cliente@example.test'));
        $sanitized = SentryEventSanitizer::sanitize($event);
        $this->assertSame([], $sanitized->getRequest());
        $this->assertSame([], $sanitized->getExtra());
        $this->assertNull($sanitized->getUser());
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + ['customer_name' => 'Cliente Test', 'customer_phone' => '55123456', 'subject' => 'Produit reçu', 'description' => 'La description détaillée de la réclamation.', 'consent' => true, 'website' => ''];
    }

    /** @return array<string, mixed> */
    private function orderAttributes(): array
    {
        return ['checkout_idempotency_key' => 'complaint-link-order', 'checkout_payload_hash' => hash('sha256', 'complaint-link-order'), 'status' => 'nouvelle', 'customer_name' => 'Cliente Test', 'customer_phone' => '55123456', 'customer_city' => 'Tunis', 'customer_address' => '10 rue des Jasmins', 'subtotal_millimes' => 20_000, 'product_discount_millimes' => 0, 'promo_code_discount_millimes' => 0, 'shipping_fee_millimes' => 0, 'total_millimes' => 20_000, 'lock_version' => 1];
    }
}
