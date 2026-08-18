<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 40);
            $table->string('phone_normalized', 32)->unique();
            $table->string('name', 180)->nullable();
            $table->string('governorate', 80)->nullable();
            $table->string('city', 160)->nullable();
            $table->text('address')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->timestamps();
            $table->index('last_order_at');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('id')->constrained('customers')->nullOnDelete();
            $table->timestamp('customer_previous_order_at')->nullable()->after('customer_id');
            $table->index('customer_previous_order_at');
        });

        Schema::create('checkout_drafts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_token')->unique();
            $table->json('customer_data');
            $table->json('cart_snapshot');
            $table->json('checkout_data')->nullable();
            // Encrypted casts are opaque strings, so this must not be a JSON
            // column (MariaDB enforces JSON validity with a check constraint).
            $table->text('attribution_snapshot')->nullable();
            $table->string('promo_code', 80)->nullable();
            $table->timestamp('last_activity_at');
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();
            $table->index(['converted_at', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_drafts');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['customer_previous_order_at']);
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('customer_previous_order_at');
        });
        Schema::dropIfExists('customers');
    }
};
