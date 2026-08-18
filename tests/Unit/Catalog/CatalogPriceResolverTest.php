<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\CatalogPriceResolver;
use PHPUnit\Framework\TestCase;

class CatalogPriceResolverTest extends TestCase
{
    public function test_it_only_applies_a_promotion_that_is_lower_than_the_applicable_regular_price(): void
    {
        $product = new Product([
            'regular_price_millimes' => 120_000,
            'promotional_price_millimes' => 135_000,
        ]);

        $this->assertSame([
            'regular_millimes' => 120_000,
            'effective_millimes' => 120_000,
        ], (new CatalogPriceResolver)->resolve($product));
    }

    public function test_it_respects_variant_price_inheritance_and_variant_specific_prices(): void
    {
        $product = new Product([
            'regular_price_millimes' => 120_000,
            'promotional_price_millimes' => 100_000,
        ]);
        $inherited = new ProductVariant([
            'regular_price_millimes' => null,
            'promotional_price_millimes' => null,
        ]);
        $specific = new ProductVariant([
            'regular_price_millimes' => 90_000,
            'promotional_price_millimes' => 95_000,
        ]);

        $resolver = new CatalogPriceResolver;

        $this->assertSame(100_000, $resolver->resolve($product, $inherited)['effective_millimes']);
        $this->assertSame(90_000, $resolver->resolve($product, $specific)['effective_millimes']);
    }
}
