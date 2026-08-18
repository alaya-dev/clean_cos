<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_restoration_markers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('restoration_reason', 40);
            $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['order_id', 'restoration_reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_restoration_markers');
    }
};
