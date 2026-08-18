<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_manage_users_and_super_admin_can_list_users(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->getJson('/api/v1/admin/users')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/admin/users')->assertOk()->assertJsonFragment(['public_id' => $admin->public_id]);
    }

    public function test_final_super_admin_cannot_be_disabled(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($owner)->patchJson('/api/v1/admin/users/'.$owner->public_id, ['is_active' => false])->assertStatus(422);
        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_super_admin_can_search_create_and_update_a_back_office_user(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $existing = User::factory()->create(['name' => 'Amira Ben Salem', 'email' => 'amira@example.test', 'role' => 'admin']);

        $this->actingAs($owner)->getJson('/api/v1/admin/users?search=Amira&per_page=15')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.public_id', $existing->public_id);

        $created = $this->actingAs($owner)->postJson('/api/v1/admin/users', [
            'name' => 'Nouvel administrateur',
            'email' => 'nouvel-admin@example.test',
            'role' => 'admin',
            'is_active' => true,
            'password' => 'mot-de-passe-solide-2026',
            'password_confirmation' => 'mot-de-passe-solide-2026',
        ])->assertCreated()->json('data.public_id');

        $this->actingAs($owner)->patchJson('/api/v1/admin/users/'.$created, [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_super_admin_can_reset_an_admin_password_without_the_current_password(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'password' => 'ancien-mot-de-passe']);

        $this->actingAs($owner)->patchJson('/api/v1/admin/users/'.$admin->public_id, [
            'password' => 'nouveau-mot-de-passe-2026',
            'password_confirmation' => 'nouveau-mot-de-passe-2026',
        ])->assertOk();

        $this->assertTrue(password_verify('nouveau-mot-de-passe-2026', (string) $admin->fresh()->password));
    }

    public function test_super_admin_cannot_update_another_super_admin_but_can_create_one(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $otherOwner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($owner)->patchJson('/api/v1/admin/users/'.$otherOwner->public_id, ['name' => 'Modification refusée'])
            ->assertStatus(422);

        $this->actingAs($owner)->postJson('/api/v1/admin/users', [
            'name' => 'Nouveau Super Admin',
            'email' => 'nouveau-super-admin@example.test',
            'role' => 'super_admin',
            'is_active' => true,
            'password' => 'mot-de-passe-super-admin-2026',
            'password_confirmation' => 'mot-de-passe-super-admin-2026',
        ])->assertCreated()->assertJsonPath('data.role', 'super_admin');
    }

    public function test_super_admin_can_delete_an_admin_without_deleting_operational_history(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($owner)->deleteJson('/api/v1/admin/users/'.$admin->public_id)
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('users', ['id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deleted', 'actor_user_id' => $owner->id]);
    }

    public function test_admin_cannot_delete_another_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $target = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->deleteJson('/api/v1/admin/users/'.$target->public_id)->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null]);
    }

    public function test_super_admin_cannot_delete_a_super_admin(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $otherOwner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($owner)->deleteJson('/api/v1/admin/users/'.$otherOwner->public_id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('user');

        $this->assertDatabaseHas('users', ['id' => $otherOwner->id, 'deleted_at' => null]);
    }
}
