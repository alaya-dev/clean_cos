<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Notifications\BackOfficeResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rate limiting is covered independently. Keep this workflow test isolated
        // from rate-limit counters shared by preceding feature tests.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_active_back_office_user_receives_a_time_limited_password_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->get(route('admin.password.request'))
            ->assertOk()
            ->assertSee('Mot de passe oublié ?')
            ->assertSee('60 minutes');

        $this->post(route('admin.password.email'), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($user, BackOfficeResetPassword::class);
    }

    public function test_reset_request_does_not_send_to_inactive_or_non_back_office_users(): void
    {
        Notification::fake();
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'is_active' => false]);

        $this->post(route('admin.password.email'), ['email' => $customer->email])->assertRedirect();
        $this->post(route('admin.password.email'), ['email' => $inactiveAdmin->email])->assertRedirect();

        Notification::assertNothingSent();
    }

    public function test_valid_reset_changes_password_and_invalidates_existing_admin_sessions(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'password' => 'old-password-123', 'auth_version' => 7]);
        $token = Password::broker()->createToken($user);

        $this->post(route('admin.password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'nouveau8',
            'password_confirmation' => 'nouveau8',
        ])->assertRedirect(route('login'));

        $fresh = $user->fresh();
        $this->assertTrue(password_verify('nouveau8', (string) $fresh->password));
        $this->assertSame(8, $fresh->auth_version);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset', 'auditable_id' => (string) $user->id]);
    }
}
