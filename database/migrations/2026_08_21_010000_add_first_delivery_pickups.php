<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('first_delivery_pickups', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('first_delivery_configuration_id')->nullable();
            $table->foreign('first_delivery_configuration_id', 'first_delivery_pickups_configuration_fk')
                ->references('id')->on('first_delivery_configurations')->nullOnDelete();
            $table->string('provider_pickup_id', 120)->nullable()->unique();
            $table->string('status', 40)->default('pending');
            $table->string('print_url', 1000)->nullable();
            $table->unsignedSmallInteger('shipment_count');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->boolean('retryable')->default(false);
            $table->boolean('print_refresh_pending')->default(false);
            $table->string('last_error', 120)->nullable();
            $table->string('print_error', 120)->nullable();
            $table->string('safe_message', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('queued_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_printed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at'], 'first_delivery_pickups_status_idx');
        });

        Schema::create('first_delivery_pickup_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('first_delivery_pickup_id');
            $table->foreign('first_delivery_pickup_id', 'first_delivery_pickup_items_pickup_fk')
                ->references('id')->on('first_delivery_pickups')->cascadeOnDelete();
            $table->foreignId('first_delivery_shipment_id')->nullable();
            $table->foreign('first_delivery_shipment_id', 'first_delivery_pickup_items_shipment_fk')
                ->references('id')->on('first_delivery_shipments')->nullOnDelete();
            $table->string('barcode', 12);
            $table->string('order_reference', 40);
            $table->timestamp('created_at');
            $table->unique('first_delivery_shipment_id', 'first_delivery_pickup_items_shipment_unique');
            $table->unique(['first_delivery_pickup_id', 'barcode'], 'first_delivery_pickup_items_barcode_unique');
        });

        Schema::create('first_delivery_pickup_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('first_delivery_pickup_id');
            $table->foreign('first_delivery_pickup_id', 'first_delivery_pickup_attempts_pickup_fk')
                ->references('id')->on('first_delivery_pickups')->cascadeOnDelete();
            $table->string('operation', 30);
            $table->unsignedInteger('attempt_number');
            $table->boolean('request_sent')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('outcome', 50);
            $table->string('error_classification', 120)->nullable();
            $table->string('safe_message', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('attempted_at');
            $table->unique(['first_delivery_pickup_id', 'operation', 'attempt_number'], 'first_delivery_pickup_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('first_delivery_pickup_attempts');
        Schema::dropIfExists('first_delivery_pickup_items');
        Schema::dropIfExists('first_delivery_pickups');
    }
};
