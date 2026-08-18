<?php

namespace Tests\Feature\MetaTracking;

use App\Domain\MetaTracking\Models\MetaConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_admin_is_denied_and_token_is_never_returned_or_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->getJson('/api/v1/admin/meta/configuration')->assertForbidden();
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $response = $this->actingAs($superAdmin)->postJson('/api/v1/admin/meta/configuration', [
            'mode' => 'test', 'pixel_id' => '1234567890', 'capi_access_token' => 'secret-capi-token', 'test_event_code' => 'TEST123',
        ])->assertCreated()->assertJsonMissing(['secret-capi-token', 'TEST123']);

        self::assertTrue($response->json('data.proposed.token_configured'));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'meta.configuration_proposed']);
        $this->actingAs($superAdmin)->getJson('/api/v1/admin/meta/configuration')
            ->assertOk()
            ->assertJsonPath('data.proposed.public_id', $response->json('data.proposed.public_id'));
    }

    public function test_successful_test_uses_proposed_configuration_and_activates_test_mode(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'safe-trace'], 200)]);
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $configuration = $this->proposed($user, true);

        $this->actingAs($user)->postJson('/api/v1/admin/meta/configuration/'.$configuration->public_id.'/test')
            ->assertOk()
            ->assertJsonPath('data.active.mode', 'test')
            ->assertJsonPath('data.test_result.events_received', 1);

        $this->assertDatabaseHas('meta_configurations', ['id' => $configuration->id, 'state' => 'active', 'last_test_http_status' => 200]);
        $this->assertDatabaseHas('meta_event_attempts', ['channel' => 'synthetic_test', 'outcome' => 'succeeded']);
    }

    public function test_failed_test_keeps_proposal_and_returns_sanitized_details(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190, 'error_subcode' => 463, 'fbtrace_id' => 'trace']], 401)]);
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $configuration = $this->proposed($user, true);

        $this->actingAs($user)->postJson('/api/v1/admin/meta/configuration/'.$configuration->public_id.'/test')
            ->assertStatus(422)
            ->assertJsonPath('meta.test_result.error_code', '190')
            ->assertJsonPath('meta.test_result.request_sent', true);

        $this->assertDatabaseHas('meta_configurations', ['id' => $configuration->id, 'state' => 'proposed', 'test_outcome' => 'failed']);
    }

    public function test_disabled_mode_activates_without_password_or_network_request(): void
    {
        Http::fake();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($user)->postJson('/api/v1/admin/meta/configuration', ['mode' => 'disabled'])
            ->assertCreated()->assertJsonPath('data.active.mode', 'disabled');

        Http::assertNothingSent();
    }

    public function test_domain_verification_tag_is_normalized_persisted_and_rendered_on_storefront_pages(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $tag = '<meta name="facebook-domain-verification" content="PC_domain-token_123">';

        $saved = $this->actingAs($user)->postJson('/api/v1/admin/meta/configuration', [
            'mode' => 'test',
            'pixel_id' => '1234567890',
            'capi_access_token' => 'secret-capi-token',
            'test_event_code' => 'TEST123',
            'facebook_domain_verification' => $tag,
        ])->assertCreated()
            ->assertJsonPath('data.proposed.domain_verification_configured', true);

        $configuration = MetaConfiguration::query()->where('public_id', $saved->json('data.proposed.public_id'))->firstOrFail();
        self::assertSame('PC_domain-token_123', $configuration->facebook_domain_verification);
        $configuration->update(['state' => 'active', 'activated_at' => now()]);
        Cache::forget('meta:active-domain-verification');

        $this->get('/')
            ->assertOk()
            ->assertSee('<meta name="facebook-domain-verification" content="PC_domain-token_123">', false);
    }

    public function test_invalid_domain_verification_markup_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($user)->postJson('/api/v1/admin/meta/configuration', [
            'mode' => 'disabled',
            'facebook_domain_verification' => '<script>alert(1)</script>',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('facebook_domain_verification');
    }

    public function test_live_mode_requires_recent_test_and_one_confirmation_only(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $configuration = $this->proposed($user, false);
        $this->actingAs($user)->postJson('/api/v1/admin/meta/configuration/'.$configuration->public_id.'/test')->assertOk();

        $this->actingAs($user)->postJson('/api/v1/admin/meta/configuration/'.$configuration->public_id.'/activate', [
            'configuration_version' => 1, 'confirm_production' => true,
        ])->assertOk()->assertJsonPath('data.active.mode', 'live');
    }

    public function test_blank_token_preserves_active_credential_through_save_activation_and_page_reload(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $active = $this->proposed($user, true);
        $active->update(['state' => 'active', 'activated_at' => now(), 'facebook_domain_verification' => 'PC_domain-token_123']);

        $saved = $this->actingAs($user)->postJson('/api/v1/admin/meta/configuration', [
            'mode' => 'test', 'pixel_id' => '1234567890', 'capi_access_token' => '', 'test_event_code' => '',
            'base_configuration_public_id' => $active->public_id,
        ])->assertCreated()->assertJsonPath('data.proposed.token_configured', true);
        $proposal = MetaConfiguration::query()->where('public_id', $saved->json('data.proposed.public_id'))->firstOrFail();
        self::assertSame('secret-capi-token', Crypt::decryptString($proposal->capi_access_token_encrypted));
        self::assertSame('PC_domain-token_123', $proposal->facebook_domain_verification);

        $this->actingAs($user)->postJson('/api/v1/admin/meta/configuration/'.$proposal->public_id.'/test')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/admin/meta/configuration')
            ->assertOk()->assertJsonPath('data.active.public_id', $proposal->public_id)
            ->assertJsonPath('data.active.token_configured', true)->assertJsonMissing(['secret-capi-token']);
        self::assertSame('secret-capi-token', Crypt::decryptString($proposal->fresh()->capi_access_token_encrypted));
    }

    public function test_active_test_configuration_can_be_tested_twice_without_resaving_credentials(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $configuration = $this->proposed($user, true);

        $this->actingAs($user)->postJson('/api/v1/admin/meta/configuration/'.$configuration->public_id.'/test')->assertOk();
        $this->actingAs($user)->postJson('/api/v1/admin/meta/configuration/'.$configuration->public_id.'/test')->assertOk();

        Http::assertSentCount(2);
        self::assertSame('secret-capi-token', Crypt::decryptString($configuration->fresh()->capi_access_token_encrypted));
    }

    public function test_non_empty_token_replaces_it_and_explicit_removal_disables_tracking(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $active = $this->proposed($user, true);
        $active->update(['state' => 'active', 'activated_at' => now()]);
        $saved = $this->actingAs($user)->postJson('/api/v1/admin/meta/configuration', [
            'mode' => 'test', 'pixel_id' => '1234567890', 'capi_access_token' => 'replacement-token',
            'test_event_code' => 'TEST456', 'base_configuration_public_id' => $active->public_id,
        ])->assertCreated();
        $proposal = MetaConfiguration::query()->where('public_id', $saved->json('data.proposed.public_id'))->firstOrFail();
        self::assertSame('replacement-token', Crypt::decryptString($proposal->capi_access_token_encrypted));

        $this->actingAs($user)->deleteJson('/api/v1/admin/meta/configuration/token', ['confirm_removal' => true])
            ->assertOk()->assertJsonPath('data.active.mode', 'disabled')
            ->assertJsonPath('data.active.token_configured', false)->assertJsonMissing(['replacement-token']);
    }

    public function test_unsent_configuration_error_does_not_claim_that_meta_rejected_the_test(): void
    {
        Http::fake();
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $configuration = $this->proposed($user, true);
        $configuration->update(['capi_access_token_encrypted' => null]);

        $this->actingAs($user)->postJson('/api/v1/admin/meta/configuration/'.$configuration->public_id.'/test')
            ->assertUnprocessable()->assertJsonPath('meta.test_result.request_sent', false)
            ->assertJsonPath('meta.test_result.classification', 'configuration_invalid')
            ->assertJsonPath('message', 'Le test n’a pas été envoyé à Meta, car la configuration CAPI est incomplète ou le jeton enregistré est indisponible.');
        Http::assertNothingSent();
    }

    private function proposed(User $user, bool $testMode): MetaConfiguration
    {
        return MetaConfiguration::query()->create([
            'configuration_version' => 1, 'state' => 'proposed', 'tracking_enabled' => true,
            'pixel_id' => '1234567890', 'capi_access_token_encrypted' => Crypt::encryptString('secret-capi-token'),
            'test_mode' => $testMode, 'test_event_code' => $testMode ? 'TEST123' : null, 'created_by' => $user->id,
        ]);
    }
}
