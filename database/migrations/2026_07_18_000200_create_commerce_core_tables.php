<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_fields', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('key', 100)->unique();
            $table->string('label', 160);
            $table->string('type', 30);
            $table->json('options')->nullable();
            $table->boolean('is_required');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order');
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_reference')->unique();
            $table->uuid('checkout_idempotency_key')->unique();
            $table->string('status', 40);
            $table->string('customer_name', 180);
            $table->string('customer_phone', 40);
            $table->string('customer_city', 160);
            $table->text('customer_address');
            $table->unsignedBigInteger('subtotal_millimes');
            $table->unsignedBigInteger('product_discount_millimes');
            $table->unsignedBigInteger('promo_code_discount_millimes');
            $table->unsignedBigInteger('shipping_fee_millimes');
            $table->unsignedBigInteger('total_millimes');
            $table->string('meta_purchase_trigger_snapshot', 20)->default('nouvelle');
            $table->string('meta_event_id', 160)->unique();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['customer_phone', 'created_at']);
        });
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name_snapshot', 200);
            $table->json('variant_snapshot')->nullable();
            $table->unsignedBigInteger('regular_unit_price_millimes');
            $table->unsignedBigInteger('effective_unit_price_millimes');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('line_total_millimes');
            $table->timestamps();
            $table->index('order_id');
        });
        Schema::create('order_checkout_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('checkout_field_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field_key_snapshot', 100);
            $table->string('label_snapshot', 160);
            $table->string('type_snapshot', 30);
            $table->json('value');
            $table->timestamps();
            $table->unique(['order_id', 'field_key_snapshot']);
        });
        Schema::create('order_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_checkout_values');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('checkout_fields');
    }
};
