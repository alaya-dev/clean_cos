<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Commerce\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_read_audit_logs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        AuditLog::query()->create(['action' => 'test', 'auditable_type' => 'test', 'auditable_id' => '1', 'before' => ['password' => 'secret', 'email' => 'client@example.test'], 'after' => ['safe' => true]]);

        $this->actingAs($admin)->getJson('/api/v1/admin/audit-logs')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/admin/audit-logs')->assertOk()->assertJsonMissing(['password' => 'secret'])->assertJsonMissing(['email' => 'client@example.test']);
    }

    public function test_super_admin_can_filter_the_paginated_audit_log(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        AuditLog::query()->create(['action' => 'user.updated', 'auditable_type' => User::class, 'auditable_id' => '1', 'actor_user_id' => $owner->id, 'actor_role_snapshot' => 'super_admin', 'after' => ['is_active' => false]]);
        AuditLog::query()->create(['action' => 'order.status_changed', 'auditable_type' => 'order', 'auditable_id' => '01ORDER', 'actor_role_snapshot' => 'admin', 'after' => ['to_status' => 'confirmee']]);
        AuditLog::query()->create(['action' => 'catalog.product_updated', 'auditable_type' => 'product', 'auditable_id' => '2', 'actor_role_snapshot' => 'admin']);

        $this->actingAs($owner)->getJson('/api/v1/admin/audit-logs?search=user&actor_role=super_admin')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.action', 'user.updated')
            ->assertJsonPath('data.data.0.actor.name', $owner->name);
    }

    public function test_journal_exposes_only_order_and_user_actions_with_a_scope_filter(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        AuditLog::query()->create(['action' => 'order.items_updated', 'auditable_type' => 'order', 'auditable_id' => '01ORDER']);
        AuditLog::query()->create(['action' => 'user.updated', 'auditable_type' => User::class, 'auditable_id' => '1']);
        AuditLog::query()->create(['action' => 'navex.shipment_synchronized', 'auditable_type' => 'shipment', 'auditable_id' => '1']);

        $this->actingAs($owner)->getJson('/api/v1/admin/audit-logs?scope=orders')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.action', 'order.items_updated');

        $this->actingAs($owner)->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->assertJsonMissing(['action' => 'navex.shipment_synchronized']);
    }

    public function test_legacy_numeric_order_audit_ids_are_displayed_as_public_references(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $order = Order::query()->create([
            'checkout_idempotency_key' => (string) str()->uuid(),
            'status' => 'nouvelle',
            'customer_name' => 'Client test',
            'customer_phone' => '22123456',
            'customer_city' => 'Tunis',
            'customer_address' => 'Rue test',
            'subtotal_millimes' => 10_000,
            'product_discount_millimes' => 0,
            'promo_code_discount_millimes' => 0,
            'shipping_fee_millimes' => 0,
            'total_millimes' => 10_000,
        ]);
        AuditLog::query()->create(['action' => 'order.total_updated', 'auditable_type' => Order::class, 'auditable_id' => (string) $order->id, 'after' => ['total_millimes' => 12_000]]);

        $this->actingAs($owner)->getJson('/api/v1/admin/audit-logs?scope=orders')
            ->assertOk()
            ->assertJsonPath('data.data.0.order_reference', $order->public_reference);
    }

    public function test_rows_include_target_and_safe_before_after_changes(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $order = Order::query()->create([
            'checkout_idempotency_key' => (string) str()->uuid(),
            'status' => 'nouvelle',
            'customer_name' => 'Client test',
            'customer_phone' => '22123456',
            'customer_city' => 'Tunis',
            'customer_address' => 'Rue test',
            'subtotal_millimes' => 10_000,
            'product_discount_millimes' => 0,
            'promo_code_discount_millimes' => 0,
            'shipping_fee_millimes' => 0,
            'total_millimes' => 10_000,
        ]);
        AuditLog::query()->create([
            'action' => 'order.total_updated',
            'auditable_type' => Order::class,
            'auditable_id' => $order->public_reference,
            'actor_user_id' => $owner->id,
            'actor_role_snapshot' => $owner->role,
            'before' => ['total_millimes' => 10_000, 'customer_phone' => '22123456'],
            'after' => ['total_millimes' => 12_000, 'customer_phone' => '22999999'],
        ]);

        $this->actingAs($owner)->getJson('/api/v1/admin/audit-logs?scope=orders')
            ->assertOk()
            ->assertJsonPath('data.data.0.target_type', 'order')
            ->assertJsonPath('data.data.0.target_reference', $order->public_reference)
            ->assertJsonPath('data.data.0.changes.0.field', 'total_millimes')
            ->assertJsonPath('data.data.0.changes.0.from', 10_000)
            ->assertJsonPath('data.data.0.changes.0.to', 12_000)
            ->assertJsonMissing(['customer_phone' => '22123456']);
    }

    public function test_audit_meta_declares_the_configured_monthly_retention_policy(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($owner)->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.meta.retention.automatic_purge', true)
            ->assertJsonPath('data.meta.retention.days', 730)
            ->assertJsonPath('data.meta.retention.label', 'Conservation : 730 jours — purge automatique mensuelle.');
    }
}
