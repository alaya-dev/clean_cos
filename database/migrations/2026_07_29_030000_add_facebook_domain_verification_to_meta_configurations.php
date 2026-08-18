<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_configurations', function (Blueprint $table): void {
            $table->string('facebook_domain_verification', 255)->nullable()->after('pixel_id');
        });
    }

    public function down(): void
    {
        Schema::table('meta_configurations', function (Blueprint $table): void {
            $table->dropColumn('facebook_domain_verification');
        });
    }
};
