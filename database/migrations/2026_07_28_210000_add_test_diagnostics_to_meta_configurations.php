<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_configurations', function (Blueprint $table): void {
            $table->boolean('last_test_request_sent')->default(false)->after('test_outcome');
            $table->unsignedSmallInteger('last_test_http_status')->nullable()->after('last_test_request_sent');
            $table->unsignedInteger('last_test_events_received')->nullable()->after('last_test_http_status');
            $table->string('last_test_error_code', 80)->nullable()->after('last_test_events_received');
            $table->string('last_test_error_subcode', 80)->nullable()->after('last_test_error_code');
            $table->string('last_test_message', 500)->nullable()->after('last_test_error_subcode');
            $table->string('last_test_fbtrace_id', 120)->nullable()->after('last_test_message');
            $table->string('last_test_classification', 80)->nullable()->after('last_test_fbtrace_id');
        });
    }

    public function down(): void
    {
        Schema::table('meta_configurations', function (Blueprint $table): void {
            $table->dropColumn(['last_test_request_sent', 'last_test_http_status', 'last_test_events_received', 'last_test_error_code', 'last_test_error_subcode', 'last_test_message', 'last_test_fbtrace_id', 'last_test_classification']);
        });
    }
};
