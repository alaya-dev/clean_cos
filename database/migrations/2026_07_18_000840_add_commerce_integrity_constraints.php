<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Variant stock is held by product_variants; normalize legacy zeroes
        // before enforcing the invariant so historical meaning is preserved.
        DB::statement('UPDATE products SET stock_quantity = NULL WHERE has_variants = 1 AND stock_quantity = 0');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_promo_less_than_regular CHECK (promotional_price_millimes IS NULL OR promotional_price_millimes < regular_price_millimes)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_non_negative CHECK (stock_quantity IS NULL OR stock_quantity >= 0)');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_valid CHECK (status IN ('nouvelle','confirmee','livree','annulee','echec_livraison','retournee'))");
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_variant_stock_mode CHECK (has_variants = 0 OR stock_quantity IS NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_valid');
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_promo_less_than_regular');
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_stock_non_negative');
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_variant_stock_mode');
    }
};
