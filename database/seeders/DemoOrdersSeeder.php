<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Commerce\Models\Order;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoOrdersSeeder extends Seeder
{
    public function run(): void
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'demo-soins'],
            ['name' => 'Soins démo', 'description' => 'Produits de démonstration pour le back-office.', 'is_active' => true, 'sort_order' => 9_999],
        );
        $product = Product::query()->firstOrCreate(
            ['slug' => 'serum-demo-commandes'],
            ['category_id' => $category->id, 'name' => 'Sérum démo commandes', 'regular_price_millimes' => 49_000, 'stock_quantity' => 50, 'is_active' => true, 'has_variants' => false, 'published_at' => now()],
        );

        foreach ([
            ['Amira Ben Salem', '22100001', 'Tunis', 'Rue du Lac, Les Berges du Lac', 'nouvelle', 49_000],
            ['Yasmine Trabelsi', '22100002', 'Sfax', 'Route de Tunis, Sfax', 'confirmee', 98_000],
            ['Meriem Gharbi', '22100003', 'Sousse', 'Avenue Hedi Chaker, Sousse', 'tentative_1', 49_000],
            ['Nour Kammoun', '22100004', 'Nabeul', 'Rue Habib Bourguiba, Nabeul', 'tentative_2', 49_000],
            ['Sana Jebali', '22100005', 'Bizerte', 'Corniche de Bizerte', 'tentative_3', 98_000],
        ] as [$customerName, $phone, $city, $address, $status, $total]) {
            $order = Order::query()->firstOrCreate(
                ['customer_name' => $customerName],
                [
                    'checkout_idempotency_key' => (string) Str::uuid(),
                    'status' => $status,
                    'customer_phone' => $phone,
                    'customer_city' => $city,
                    'customer_address' => $address,
                    'subtotal_millimes' => $total,
                    'product_discount_millimes' => 0,
                    'promo_code_discount_millimes' => 0,
                    'shipping_fee_millimes' => 9_000,
                    'total_millimes' => $total + 9_000,
                ],
            );

            $quantity = intdiv($total, 49_000);
            $order->items()->firstOrCreate(
                ['product_id' => $product->id],
                [
                    'product_name_snapshot' => $product->name,
                    'regular_unit_price_millimes' => 49_000,
                    'effective_unit_price_millimes' => 49_000,
                    'quantity' => $quantity,
                    'line_total_millimes' => $total,
                ],
            );
            $order->statusHistory()->firstOrCreate(
                ['to_status' => $status],
                ['from_status' => null, 'created_at' => now()->subDays(array_search($status, ['nouvelle', 'confirmee', 'tentative_1', 'tentative_2', 'tentative_3'], true) ?: 0)],
            );
        }
    }
}
