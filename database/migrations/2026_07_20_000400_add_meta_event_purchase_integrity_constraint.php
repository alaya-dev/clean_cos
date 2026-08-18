<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE meta_events ADD CONSTRAINT meta_events_purchase_order_check CHECK ((event_name <> 'Purchase') OR order_id IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE meta_events DROP CONSTRAINT meta_events_purchase_order_check');
    }
};
