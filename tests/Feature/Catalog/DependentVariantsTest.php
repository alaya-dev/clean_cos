<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\CreateProductAction;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DependentVariantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dependent_values_create_only_valid_sellable_combinations_with_variant_prices(): void
    {
        $category = Category::query()->create(['name' => 'Soins', 'slug' => 'soins', 'is_active' => true]);
        $product = app(CreateProductAction::class)->handle([
            'category_public_id' => $category->public_id,
            'name' => 'Spray',
            'slug' => 'spray',
            'regular_price_millimes' => 20_000,
            'promotional_price_millimes' => null,
            'stock_quantity' => null,
            'low_stock_threshold' => null,
            'is_active' => true,
            'has_variants' => true,
            'option_groups' => [
                ['name' => 'Format', 'values' => [
                    ['client_key' => 'size-100', 'value' => '100 ml'],
                    ['client_key' => 'size-250', 'value' => '250 ml'],
                ]],
                ['name' => 'Couleur', 'values' => [
                    ['client_key' => 'red-100', 'value' => 'Rouge', 'parent_client_key' => 'size-100'],
                    ['client_key' => 'blue-100', 'value' => 'Bleu', 'parent_client_key' => 'size-100'],
                    ['client_key' => 'black-250', 'value' => 'Noir', 'parent_client_key' => 'size-250'],
                ]],
            ],
            'variants' => [
                ['option_value_client_keys' => ['size-100', 'red-100'], 'sku' => 'SPRAY-100-RED', 'regular_price_millimes' => 22_000, 'promotional_price_millimes' => 19_000, 'stock_quantity' => 3, 'is_active' => true, 'is_default' => true],
                ['option_value_client_keys' => ['size-100', 'blue-100'], 'sku' => 'SPRAY-100-BLUE', 'stock_quantity' => 2, 'is_active' => true],
                ['option_value_client_keys' => ['size-250', 'black-250'], 'sku' => 'SPRAY-250-BLACK', 'regular_price_millimes' => 27_000, 'stock_quantity' => 4, 'is_active' => true],
            ],
        ]);

        $product->load('optionGroups.values.parentValue', 'variants.values');
        self::assertCount(3, $product->variants);
        self::assertSame('100 ml', $product->optionGroups[1]->values[0]->parentValue?->value);
        self::assertTrue($product->variants->firstWhere('sku', 'SPRAY-100-RED')->is_default);
        self::assertSame(19_000, $product->variants->firstWhere('sku', 'SPRAY-100-RED')->promotional_price_millimes);
        self::assertNull($product->variants->firstWhere('sku', 'SPRAY-100-BLUE')->regular_price_millimes);
    }

    public function test_cart_quote_keeps_dependent_combinations_separate_and_uses_variant_prices(): void
    {
        $product = $this->dependentProduct();
        $red = $product->variants->firstWhere('sku', 'SPRAY-100-RED');
        $black = $product->variants->firstWhere('sku', 'SPRAY-250-BLACK');

        $this->postJson('/api/v1/public/cart/quote', ['items' => [
            ['product_public_id' => $product->public_id, 'variant_public_id' => $red->public_id, 'quantity' => 1],
            ['product_public_id' => $product->public_id, 'variant_public_id' => $black->public_id, 'quantity' => 1],
        ]])->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.effective_unit_price.millimes', 19_000)
            ->assertJsonPath('data.items.1.effective_unit_price.millimes', 27_000);
    }

    private function dependentProduct(): Product
    {
        $category = Category::query()->create(['name' => 'Soins', 'slug' => 'soins-'.str()->random(5), 'is_active' => true]);

        return app(CreateProductAction::class)->handle([
            'category_public_id' => $category->public_id, 'name' => 'Spray', 'slug' => 'spray-'.str()->random(5), 'regular_price_millimes' => 20_000, 'stock_quantity' => null, 'is_active' => true, 'has_variants' => true, 'option_groups' => [
                ['name' => 'Format', 'values' => [['client_key' => 'size-100', 'value' => '100 ml'], ['client_key' => 'size-250', 'value' => '250 ml']]],
                ['name' => 'Couleur', 'values' => [['client_key' => 'red-100', 'value' => 'Rouge', 'parent_client_key' => 'size-100'], ['client_key' => 'black-250', 'value' => 'Noir', 'parent_client_key' => 'size-250']]],
            ], 'variants' => [
                ['option_value_client_keys' => ['size-100', 'red-100'], 'sku' => 'SPRAY-100-RED', 'regular_price_millimes' => 22_000, 'promotional_price_millimes' => 19_000, 'stock_quantity' => 3, 'is_active' => true, 'is_default' => true],
                ['option_value_client_keys' => ['size-250', 'black-250'], 'sku' => 'SPRAY-250-BLACK', 'regular_price_millimes' => 27_000, 'stock_quantity' => 4, 'is_active' => true],
            ],
        ])->load('variants.values');
    }
}
