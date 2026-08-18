<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_valid');
        DB::table('orders')->where('status', 'livree')->update(['status' => 'confirmee']);
        DB::table('orders')->where('status', 'echec_livraison')->update(['status' => 'tentative_1']);
        DB::table('orders')->where('status', 'retournee')->update(['status' => 'annulee']);
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_valid CHECK (status IN ('nouvelle','confirmee','tentative_1','tentative_2','tentative_3','annulee'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_valid');
        DB::table('orders')->whereIn('status', ['tentative_1', 'tentative_2', 'tentative_3'])->update(['status' => 'echec_livraison']);
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_valid CHECK (status IN ('nouvelle','confirmee','livree','annulee','echec_livraison','retournee'))");
    }
};
