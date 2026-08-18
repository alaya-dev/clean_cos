<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Support\AssertsApiEnvelope;
use Tests\TestCase;

class ApiErrorEnvelopeTest extends TestCase
{
    use AssertsApiEnvelope;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);

        RouteFacade::get('/api/v1/test/rate-limit', fn () => abort(429));
        RouteFacade::get('/api/v1/test/boom', fn () => throw new \RuntimeException('secret boom'));
    }

    public function test_validation_failure_uses_common_envelope(): void
    {
        $this->postJson('/api/v1/public/orders', [])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_unauthenticated_failure_uses_common_envelope(): void
    {
        $this->getJson('/api/v1/admin/orders')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_forbidden_failure_uses_common_envelope(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $this->actingAs($user)->getJson('/api/v1/admin/orders')->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_missing_resource_uses_common_envelope(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user)->getJson('/api/v1/admin/products/does-not-exist')->assertStatus(404)->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_rate_limit_uses_common_envelope(): void
    {
        $this->getJson('/api/v1/test/rate-limit')->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
    }

    public function test_unexpected_failure_is_safe(): void
    {
        $response = $this->getJson('/api/v1/test/boom')->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
        $this->assertDoesNotLeakSecrets($response->json());
    }
}
