<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('is_exchange')->default(false)->after('customer_address');
            $table->string('exchange_article_designation', 500)->nullable()->after('is_exchange');
            $table->unsignedInteger('exchange_article_count')->nullable()->after('exchange_article_designation');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['is_exchange', 'exchange_article_designation', 'exchange_article_count']);
        });
    }
};
