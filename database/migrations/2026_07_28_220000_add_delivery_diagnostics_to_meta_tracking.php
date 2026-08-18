<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_events', function (Blueprint $table): void {
            $table->text('user_data_encrypted')->nullable()->after('context_summary');
        });
        Schema::table('meta_event_attempts', function (Blueprint $table): void {
            $table->boolean('request_sent')->default(false)->after('outcome');
            $table->unsignedInteger('events_received')->nullable()->after('http_status');
            $table->string('meta_error_code', 80)->nullable()->after('error_classification');
            $table->string('meta_error_subcode', 80)->nullable()->after('meta_error_code');
            $table->string('safe_message', 500)->nullable()->after('meta_error_subcode');
            $table->string('fbtrace_id', 120)->nullable()->after('safe_message');
            $table->string('graph_api_version', 20)->nullable()->after('fbtrace_id');
        });
    }

    public function down(): void
    {
        Schema::table('meta_event_attempts', function (Blueprint $table): void {
            $table->dropColumn(['request_sent', 'events_received', 'meta_error_code', 'meta_error_subcode', 'safe_message', 'fbtrace_id', 'graph_api_version']);
        });
        Schema::table('meta_events', function (Blueprint $table): void {
            $table->dropColumn('user_data_encrypted');
        });
    }
};
