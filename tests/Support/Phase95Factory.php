<?php

namespace Tests\Support;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Commerce\Actions\CreateGuestOrderAction;
use App\Domain\Commerce\Models\CheckoutField;
use App\Models\User;

class Phase95Factory
{
    /** @return array<string, mixed> */
    public static function checkoutField(string $key, string $label, string $type, bool $required = true, array $options = [], bool $active = true, bool $system = true, int $sortOrder = 1): array
    {
        return CheckoutField::query()->create(['key' => $key, 'label' => $label, 'type' => $type, 'options' => $options ?: null, 'is_required' => $required, 'is_active' => $active, 'is_system' => $system, 'sort_order' => $sortOrder])->toArray();
    }

    public static function user(string $role = 'admin', bool $active = true): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => $active]);
    }

    public static function product(int $stock = 5, int $regular = 12_000, ?int $promo = null, bool $active = true, bool $variants = false): Product
    {
        $category = Category::query()->create(['name' => 'Cat '.str()->random(6), 'slug' => 'cat-'.str()->random(8), 'is_active' => true]);

        return Product::query()->create(['category_id' => $category->id, 'name' => 'Produit '.str()->random(6), 'slug' => 'produit-'.str()->random(8), 'regular_price_millimes' => $regular, 'promotional_price_millimes' => $promo, 'stock_quantity' => $stock, 'is_active' => $active, 'has_variants' => $variants, 'published_at' => now()]);
    }

    /** @return array<string, mixed> */
    public static function guestOrderPayload(Product $product, int $quantity = 1, array $customer = []): array
    {
        $fields = CheckoutField::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn (CheckoutField $field) => $field->only(['key', 'label', 'type', 'is_required', 'options', 'sort_order']))->values()->all();

        return [
            'checkout_schema_version' => app(CreateGuestOrderAction::class)->schemaVersion($fields),
            'customer' => $customer + ['full_name' => 'Client Test', 'phone' => '22 123 456', 'city' => 'Tunis', 'governorate' => 'Tunis', 'address' => '10 rue de la Paix'],
            'items' => [['product_public_id' => $product->public_id, 'variant_public_id' => null, 'quantity' => $quantity]],
        ];
    }
}
