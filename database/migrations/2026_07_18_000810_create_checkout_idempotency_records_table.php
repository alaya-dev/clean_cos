<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_idempotency_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->char('canonical_payload_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_idempotency_records');
    }
};
