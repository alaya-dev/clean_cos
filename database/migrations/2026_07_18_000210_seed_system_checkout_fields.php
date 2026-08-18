<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([['full_name', 'Nom et prénom', 'text'], ['phone', 'Téléphone', 'text'], ['city', 'Ville', 'text'], ['address', 'Adresse', 'textarea']] as $index => [$key, $label, $type]) {
            DB::table('checkout_fields')->updateOrInsert(['key' => $key], ['public_id' => (string) Str::ulid(), 'label' => $label, 'type' => $type, 'is_required' => true, 'is_active' => true, 'is_system' => true, 'sort_order' => $index + 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('checkout_fields')->whereIn('key', ['full_name', 'phone', 'city', 'address'])->delete();
    }
};
