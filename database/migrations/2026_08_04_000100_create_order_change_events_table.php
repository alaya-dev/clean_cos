<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_change_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->ulid('order_public_reference');
            $table->string('change_type', 20);
            $table->timestamp('created_at');
            $table->index(['order_public_reference', 'id']);
            $table->index(['change_type', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_change_events');
    }
};
