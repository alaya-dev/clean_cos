<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_configurations', function (Blueprint $table): void {
            $table->string('last_test_graph_api_version', 20)->nullable()->after('last_test_classification');
            $table->string('last_test_source_url', 500)->nullable()->after('last_test_graph_api_version');
        });
    }

    public function down(): void
    {
        Schema::table('meta_configurations', function (Blueprint $table): void {
            $table->dropColumn(['last_test_graph_api_version', 'last_test_source_url']);
        });
    }
};
