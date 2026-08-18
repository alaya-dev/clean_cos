<?php

use App\Domain\Checkout\Support\TunisianGovernorates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('customer_governorate', 80)->nullable()->after('customer_city');
            $table->index('customer_governorate');
        });

        Schema::create('navex_configurations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('mode', 20)->default('disabled');
            $table->string('api_base_url', 255)->default('https://app.navex.tn');
            $table->text('creation_credential_encrypted')->nullable();
            $table->text('tracking_credential_encrypted')->nullable();
            $table->text('deletion_credential_encrypted')->nullable();
            $table->string('sender_name', 180)->nullable();
            $table->string('sender_location', 300)->nullable();
            $table->string('sender_governorate', 80)->nullable();
            $table->string('parcel_opening_option', 40)->default('Non');
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 40)->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['mode', 'updated_at']);
        });

        Schema::create('navex_shipments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('navex_configuration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 40)->default('non_envoyee');
            $table->string('tracking_code', 120)->nullable();
            $table->string('raw_status', 180)->nullable();
            $table->string('raw_reason', 500)->nullable();
            $table->string('previous_raw_status', 180)->nullable();
            $table->string('previous_raw_reason', 500)->nullable();
            $table->string('courier_name', 180)->nullable();
            $table->string('courier_phone', 40)->nullable();
            $table->timestamp('provider_status_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_synchronized_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->string('last_error_classification', 80)->nullable();
            $table->longText('request_snapshot_encrypted')->nullable();
            $table->string('creation_mode', 20);
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique('order_id');
            $table->unique('tracking_code');
            $table->index(['status', 'next_retry_at']);
            $table->index(['last_synchronized_at', 'status']);
        });

        Schema::create('navex_shipment_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('navex_shipment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 40);
            $table->string('raw_status', 180)->nullable();
            $table->string('raw_reason', 500)->nullable();
            $table->timestamp('provider_status_at')->nullable();
            $table->dateTime('recorded_at');
            $table->index(['navex_shipment_id', 'recorded_at'], 'navex_history_shipment_recorded_idx');
        });

        Schema::create('navex_shipment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('navex_shipment_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 30);
            $table->unsignedTinyInteger('attempt_number');
            $table->boolean('request_sent')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('outcome', 40);
            $table->string('error_classification', 80)->nullable();
            $table->string('safe_message', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->dateTime('attempted_at');
            $table->unique(['navex_shipment_id', 'operation', 'attempt_number'], 'navex_attempt_shipment_operation_unique');
            $table->index(['navex_shipment_id', 'attempted_at'], 'navex_attempt_shipment_attempted_idx');
        });

        $existingGovernorate = DB::table('checkout_fields')->where('key', 'governorate')->first();
        DB::table('checkout_fields')->updateOrInsert(
            ['key' => 'governorate'],
            [
                'public_id' => $existingGovernorate?->public_id ?? (string) Str::ulid(),
                'label' => 'Gouvernorat',
                'type' => 'select',
                'options' => json_encode(TunisianGovernorates::ALL, JSON_THROW_ON_ERROR),
                'is_required' => true,
                'is_active' => true,
                'is_system' => true,
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('navex_shipment_attempts');
        Schema::dropIfExists('navex_shipment_status_history');
        Schema::dropIfExists('navex_shipments');
        Schema::dropIfExists('navex_configurations');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['customer_governorate']);
            $table->dropColumn('customer_governorate');
        });

        DB::table('checkout_fields')->where('key', 'governorate')->delete();
    }
};
