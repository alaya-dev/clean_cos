<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('checkout_drafts')) {
            return;
        }

        Schema::table('checkout_drafts', function (Blueprint $table): void {
            $table->text('attribution_snapshot')->nullable()->change();
        });

        DB::table('checkout_drafts')
            ->whereNotNull('attribution_snapshot')
            ->orderBy('id')
            ->chunkById(100, function ($drafts): void {
                foreach ($drafts as $draft) {
                    $stored = (string) $draft->attribution_snapshot;
                    if ($stored === '') {
                        continue;
                    }

                    $decoded = json_decode($stored, true);
                    if (is_array($decoded)) {
                        $stored = Crypt::encryptString(json_encode($decoded, JSON_THROW_ON_ERROR));
                    }

                    DB::table('checkout_drafts')->where('id', $draft->id)->update(['attribution_snapshot' => $stored]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('checkout_drafts')) {
            return;
        }

        DB::table('checkout_drafts')
            ->whereNotNull('attribution_snapshot')
            ->orderBy('id')
            ->chunkById(100, function ($drafts): void {
                foreach ($drafts as $draft) {
                    try {
                        $decoded = json_decode(Crypt::decryptString((string) $draft->attribution_snapshot), true, flags: JSON_THROW_ON_ERROR);
                    } catch (Throwable) {
                        continue;
                    }

                    DB::table('checkout_drafts')->where('id', $draft->id)->update(['attribution_snapshot' => json_encode($decoded, JSON_THROW_ON_ERROR)]);
                }
            });

        Schema::table('checkout_drafts', function (Blueprint $table): void {
            $table->json('attribution_snapshot')->nullable()->change();
        });
    }
};
