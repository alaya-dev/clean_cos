<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('first_delivery_configurations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('mode', 20)->default('disabled');
            $table->string('api_base_url', 255)->default('https://www.firstdeliverygroup.com/api/v2');
            $table->text('token_encrypted')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 40)->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->timestamp('last_localities_synced_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['mode', 'updated_at']);
        });

        Schema::create('first_delivery_localities', function (Blueprint $table): void {
            $table->unsignedBigInteger('locality_id')->primary();
            $table->string('locality_name', 180);
            $table->string('delegation_name', 180);
            $table->string('governorate_name', 120);
            $table->timestamp('last_synced_at');
            $table->timestamps();
            $table->index(['governorate_name', 'delegation_name'], 'first_delivery_localities_governorate_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('first_delivery_locality_id')->nullable()->after('customer_governorate');
            $table->foreign('first_delivery_locality_id', 'orders_first_delivery_locality_fk')
                ->references('locality_id')
                ->on('first_delivery_localities')
                ->nullOnDelete();
            $table->index('first_delivery_locality_id', 'orders_first_delivery_locality_idx');
        });

        Schema::create('first_delivery_shipments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('first_delivery_configuration_id')->nullable();
            $table->foreign('first_delivery_configuration_id', 'first_delivery_shipments_configuration_fk')
                ->references('id')
                ->on('first_delivery_configurations')
                ->nullOnDelete();
            $table->unsignedBigInteger('locality_id');
            $table->foreign('locality_id', 'first_delivery_shipments_locality_fk')
                ->references('locality_id')
                ->on('first_delivery_localities')
                ->restrictOnDelete();
            $table->string('local_status', 50)->default('non_envoyee');
            $table->string('barcode', 120)->nullable();
            $table->unsignedSmallInteger('remote_state_code')->nullable();
            $table->string('remote_state', 180)->nullable();
            $table->string('print_url', 1000)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('last_error', 120)->nullable();
            $table->longText('request_snapshot_encrypted')->nullable();
            $table->string('creation_mode', 20);
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique('order_id');
            $table->unique('barcode');
            $table->index(['local_status', 'next_retry_at'], 'first_delivery_shipments_retry_idx');
            $table->index(['last_synced_at', 'local_status'], 'first_delivery_shipments_sync_idx');
        });

        Schema::create('first_delivery_shipment_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('first_delivery_shipment_id');
            $table->foreign('first_delivery_shipment_id', 'first_delivery_history_shipment_fk')
                ->references('id')
                ->on('first_delivery_shipments')
                ->cascadeOnDelete();
            $table->string('local_status', 50);
            $table->unsignedSmallInteger('remote_state_code')->nullable();
            $table->string('remote_state', 180)->nullable();
            $table->dateTime('recorded_at');
            $table->index(['first_delivery_shipment_id', 'recorded_at'], 'first_delivery_history_shipment_idx');
        });

        Schema::create('first_delivery_shipment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('first_delivery_shipment_id');
            $table->foreign('first_delivery_shipment_id', 'first_delivery_attempts_shipment_fk')
                ->references('id')
                ->on('first_delivery_shipments')
                ->cascadeOnDelete();
            $table->string('operation', 30);
            $table->unsignedInteger('attempt_number');
            $table->boolean('request_sent')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('outcome', 50);
            $table->string('error_classification', 120)->nullable();
            $table->string('safe_message', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->dateTime('attempted_at');
            $table->unique(['first_delivery_shipment_id', 'operation', 'attempt_number'], 'first_delivery_attempt_operation_unique');
            $table->index(['first_delivery_shipment_id', 'attempted_at'], 'first_delivery_attempt_shipment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('first_delivery_shipment_attempts');
        Schema::dropIfExists('first_delivery_shipment_status_history');
        Schema::dropIfExists('first_delivery_shipments');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign('orders_first_delivery_locality_fk');
            $table->dropIndex('orders_first_delivery_locality_idx');
            $table->dropColumn('first_delivery_locality_id');
        });

        Schema::dropIfExists('first_delivery_localities');
        Schema::dropIfExists('first_delivery_configurations');
    }
};
