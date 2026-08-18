<?php

namespace Tests\Feature\Identity;

use App\Domain\Commerce\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BackOfficePasswordLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_user_is_safe_and_password_change_is_write_only(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);

        $this->actingAs($user)->getJson('/api/v1/admin/me')->assertOk()->assertJsonMissingPath('data.password');
        $this->actingAs($user)->postJson('/api/v1/admin/me/password', ['current_password' => 'old-password-123', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertOk()->assertJsonMissingPath('data.password');
        $this->assertTrue(password_verify('new-password-123', (string) $user->fresh()->password));
    }

    public function test_current_user_exposes_the_active_new_order_count_for_the_admin_shell(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->makeOrder('nouvelle');
        $this->makeOrder('nouvelle');
        $this->makeOrder('nouvelle', now());
        $this->makeOrder('confirmee');

        $response = $this->actingAs($user)->getJson('/api/v1/admin/me')->assertOk();

        $this->assertSame(2, $response->json('data.new_orders_count'));
    }

    public function test_password_change_accepts_eight_characters_and_returns_french_confirmation_feedback(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);

        $this->actingAs($user)->postJson('/api/v1/admin/me/password', [
            'current_password' => 'old-password-123',
            'password' => 'nouveau8',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonPath('errors.password.0', 'La confirmation du nouveau mot de passe ne correspond pas.');

        $this->actingAs($user)->postJson('/api/v1/admin/me/password', [
            'current_password' => 'old-password-123',
            'password' => 'nouveau8',
            'password_confirmation' => 'nouveau8',
        ])->assertOk();

        $this->assertTrue(password_verify('nouveau8', (string) $user->fresh()->password));
    }

    public function test_admin_login_has_a_relaxed_but_bounded_rate_limit(): void
    {
        $route = Route::getRoutes()->match(Request::create('/admin/login', 'POST'));

        $this->assertContains('throttle:8,1', $route->middleware());
    }

    private function makeOrder(string $status, ?Carbon $archivedAt = null): Order
    {
        return Order::query()->create([
            'checkout_idempotency_key' => (string) str()->uuid(),
            'checkout_payload_hash' => str()->random(64),
            'status' => $status,
            'customer_name' => 'Client test',
            'customer_phone' => '22123456',
            'customer_city' => 'Tunis',
            'customer_address' => 'Rue test',
            'subtotal_millimes' => 10_000,
            'product_discount_millimes' => 0,
            'promo_code_discount_millimes' => 0,
            'shipping_fee_millimes' => 0,
            'total_millimes' => 10_000,
            'archived_at' => $archivedAt,
        ]);
    }
}
