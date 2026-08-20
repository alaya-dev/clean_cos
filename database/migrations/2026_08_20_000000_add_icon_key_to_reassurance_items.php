<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reassurance_items', function (Blueprint $table): void {
            $table->string('icon_key', 40)->default('livraison_rapide')->after('icon');
        });

        DB::table('reassurance_items')->where('icon', 'shield')->update(['icon_key' => 'teste_dermatologiquement']);
        DB::table('reassurance_items')->where('icon', 'heart')->update(['icon_key' => 'ingredients_naturels']);
        DB::table('reassurance_items')->where('icon', 'message-circle')->update(['icon_key' => 'paiement_livraison']);
        DB::table('reassurance_items')->where('icon', 'truck')->update(['icon_key' => 'livraison_rapide']);
        DB::table('reassurance_items')->whereNotIn('icon', ['shield', 'heart', 'message-circle', 'truck'])->update(['icon_key' => 'livraison_rapide']);
    }

    public function down(): void
    {
        Schema::table('reassurance_items', function (Blueprint $table): void {
            $table->dropColumn('icon_key');
        });
    }
};
