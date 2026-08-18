<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_maintenance_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('task', 80)->index();
            $table->string('status', 20)->index();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('counts')->nullable();
            $table->string('error_code', 160)->nullable();
            $table->timestamps();
            $table->index(['task', 'status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_maintenance_runs');
    }
};
