<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_consents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedInteger('policy_version');
            $table->boolean('necessary_consent')->default(true);
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('decided_at');
            $table->timestamp('updated_at')->nullable();
            $table->index(['policy_version', 'marketing_consent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_consents');
    }
};
