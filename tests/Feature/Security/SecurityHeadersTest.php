<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_public_responses_receive_the_browser_security_baseline(): void
    {
        $response = $this->get('/produits');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=(), usb=()')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Content-Security-Policy-Report-Only');
    }

    public function test_admin_responses_are_not_cacheable(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('noindex, nofollow, noarchive', false);
    }

    public function test_expired_browser_admin_session_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'auth_version' => 2]);

        $this->actingAs($user)->withSession([
            'admin_authenticated_at' => now()->subMinutes((int) config('security.admin_absolute_session_minutes') + 1)->timestamp,
            'admin_auth_version' => 2,
        ])->get('/admin')->assertRedirect(route('login'));
    }
}
