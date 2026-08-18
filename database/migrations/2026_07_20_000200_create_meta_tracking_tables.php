<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_configurations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedInteger('configuration_version');
            $table->enum('state', ['proposed', 'active', 'superseded'])->index();
            $table->boolean('tracking_enabled')->default(false);
            $table->string('pixel_id', 100)->nullable();
            $table->text('capi_access_token_encrypted')->nullable();
            $table->boolean('test_mode')->default(false);
            $table->string('test_event_code', 120)->nullable();
            $table->timestamp('tested_at')->nullable();
            $table->string('test_outcome', 40)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['configuration_version', 'state']);
            $table->index(['state', 'tracking_enabled']);
        });

        Schema::create('meta_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('event_id', 120)->unique();
            $table->enum('event_name', ['PageView', 'ViewContent', 'Search', 'AddToCart', 'InitiateCheckout', 'Purchase']);
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('meta_configuration_id')->nullable()->constrained('meta_configurations')->nullOnDelete();
            $table->timestamp('event_time');
            $table->unsignedInteger('consent_policy_version');
            $table->boolean('marketing_consent');
            $table->string('source_url', 500)->nullable();
            $table->json('context_summary')->nullable();
            $table->string('payload_hash', 64);
            $table->enum('browser_state', ['eligible', 'rendered', 'attempted', 'blocked_or_unknown'])->default('eligible');
            $table->enum('capi_state', ['pending', 'sending', 'succeeded', 'temporary_failure', 'permanent_failure', 'skipped_no_consent', 'skipped_tracking_disabled', 'skipped_no_active_configuration'])->default('pending');
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('last_error_classification', 80)->nullable();
            $table->timestamp('capi_delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'event_name']);
            $table->index(['event_name', 'created_at']);
            $table->index(['capi_state', 'next_retry_at']);
            $table->index(['meta_configuration_id', 'created_at']);
        });

        Schema::create('meta_event_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meta_event_id')->constrained('meta_events')->cascadeOnDelete();
            $table->enum('channel', ['capi', 'synthetic_test']);
            $table->unsignedTinyInteger('attempt_number');
            $table->string('outcome', 40);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_classification', 80)->nullable();
            $table->timestamp('attempted_at');
            $table->unique(['meta_event_id', 'channel', 'attempt_number']);
            $table->index(['meta_event_id', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_event_attempts');
        Schema::dropIfExists('meta_events');
        Schema::dropIfExists('meta_configurations');
    }
};
