<?php

namespace Tests\Feature\StoreManagement;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Commerce\Models\CheckoutField;
use App\Domain\Commerce\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CheckoutFieldManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_system_fields_are_protected_and_only_super_admin_can_manage_schema(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $system = CheckoutField::query()->where('key', 'phone')->firstOrFail();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/checkout-fields')->assertForbidden();
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/admin/checkout-fields')->assertOk();
        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/checkout-fields/'.$system->public_id, ['key' => 'unsafe_phone', 'type' => 'number'])->assertUnprocessable();
        $this->actingAs($superAdmin, 'sanctum')->deleteJson('/api/v1/admin/checkout-fields/'.$system->public_id)->assertUnprocessable();
        $this->assertDatabaseHas('checkout_fields', ['key' => 'phone', 'type' => 'text', 'is_system' => true]);
    }

    public function test_custom_fields_can_be_created_reordered_and_toggle_required_or_active(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $created = $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/admin/checkout-fields', [
            'key' => 'instructions', 'label' => 'Instructions', 'type' => 'textarea', 'options' => null,
            'is_required' => false, 'is_active' => true, 'sort_order' => 10,
        ])->assertCreated();
        $publicId = $created->json('data.public_id');
        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/checkout-fields/'.$publicId, ['is_required' => true, 'is_active' => false])->assertOk();
        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/admin/checkout-fields/reorder', ['items' => [['public_id' => $publicId, 'sort_order' => 0]]])->assertOk();

        $this->assertDatabaseHas('checkout_fields', ['public_id' => $publicId, 'is_required' => true, 'is_active' => false, 'sort_order' => 0]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'checkout.fields_reordered']);
    }

    public function test_required_optional_choice_unknown_inactive_and_stale_submissions_are_enforced(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $product = $this->product();
        $oldSchema = $this->getJson('/api/v1/public/checkout-fields')->json('meta.schema_version');
        $choice = $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/admin/checkout-fields', [
            'key' => 'emballage', 'label' => 'Emballage', 'type' => 'radio', 'options' => ['simple', 'cadeau'],
            'is_required' => true, 'is_active' => true, 'sort_order' => 9,
        ])->assertCreated()->json('data.public_id');

        $stale = $this->payload($product, $oldSchema);
        $stale['customer']['emballage'] = 'simple';
        $this->order('4af95712-4d91-4c57-8d29-917324205101', $stale)->assertConflict()->assertJsonPath('code', 'CHECKOUT_SCHEMA_STALE');

        $schema = $this->getJson('/api/v1/public/checkout-fields')->json('meta.schema_version');
        $missing = $this->payload($product, $schema);
        $this->order('4af95712-4d91-4c57-8d29-917324205102', $missing)->assertUnprocessable();
        $invalid = $this->payload($product, $schema);
        $invalid['customer']['emballage'] = 'premium';
        $this->order('4af95712-4d91-4c57-8d29-917324205103', $invalid)->assertUnprocessable();
        $unknown = $this->payload($product, $schema);
        $unknown['customer']['emballage'] = 'simple';
        $unknown['customer']['inconnu'] = 'x';
        $this->order('4af95712-4d91-4c57-8d29-917324205104', $unknown)->assertUnprocessable();

        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/checkout-fields/'.$choice, ['is_required' => false, 'is_active' => false])->assertOk();
        $inactiveSchema = $this->getJson('/api/v1/public/checkout-fields')->json('meta.schema_version');
        $inactive = $this->payload($product, $inactiveSchema);
        $inactive['customer']['emballage'] = 'simple';
        $this->order('4af95712-4d91-4c57-8d29-917324205105', $inactive)->assertUnprocessable();
        $this->order('4af95712-4d91-4c57-8d29-917324205106', $this->payload($product, $inactiveSchema))->assertCreated();
    }

    public function test_order_snapshots_are_immutable_and_idempotent_with_custom_fields(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $product = $this->product(2);
        $fieldId = $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/admin/checkout-fields', [
            'key' => 'message', 'label' => 'Message initial', 'type' => 'text', 'options' => null,
            'is_required' => false, 'is_active' => true, 'sort_order' => 9,
        ])->assertCreated()->json('data.public_id');
        $schema = $this->getJson('/api/v1/public/checkout-fields')->json('meta.schema_version');
        $payload = $this->payload($product, $schema);
        $payload['customer']['message'] = 'Bonjour';
        $first = $this->order('4af95712-4d91-4c57-8d29-917324205107', $payload)->assertCreated();
        $this->order('4af95712-4d91-4c57-8d29-917324205107', $payload)->assertOk()->assertJsonPath('data.order.public_reference', $first->json('data.order.public_reference'));

        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/checkout-fields/'.$fieldId, ['label' => 'Message futur'])->assertOk();
        $snapshot = Order::query()->firstOrFail()->checkoutValues()->where('field_key_snapshot', 'message')->firstOrFail();
        $this->assertSame('Message initial', $snapshot->label_snapshot);
        $this->assertSame('text', $snapshot->type_snapshot);
        $this->assertFalse($snapshot->is_required_snapshot);
        $this->assertSame('Bonjour', $snapshot->value);
        $this->assertSame(1, Order::query()->count());
    }

    private function order(string $key, array $payload): TestResponse
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/public/orders', $payload);
    }

    private function product(int $stock = 10): Product
    {
        $category = Category::query()->create(['name' => 'Visage', 'slug' => 'visage-'.str()->random(8), 'is_active' => true]);

        return Product::query()->create(['category_id' => $category->id, 'name' => 'Sérum', 'slug' => 'serum-'.str()->random(8), 'regular_price_millimes' => 20_000, 'stock_quantity' => $stock, 'is_active' => true, 'has_variants' => false, 'published_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function payload(Product $product, string $schema): array
    {
        return ['checkout_schema_version' => $schema, 'customer' => ['full_name' => 'Cliente Test', 'phone' => '22123456', 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue des Jasmins'], 'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => 1]]];
    }
}
