<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            foreach (['preview', 'final'] as $kind) {
                if (! Schema::hasColumn('galleries', $kind.'_share_status')) {
                    $table->string($kind.'_share_status')->default('offline_only');
                    $table->text($kind.'_share_error')->nullable();
                    $table->timestamp($kind.'_shared_at')->nullable();
                }
            }
            if (! Schema::hasColumn('galleries', 'selection_share_error')) {
                $table->text('selection_share_error')->nullable();
            }
        });
        Schema::table('sync_jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('sync_jobs', 'explicit_requested_at')) {
                $table->timestamp('explicit_requested_at')->nullable();
                $table->unsignedBigInteger('last_photo_id')->default(0);
            }
        });
        // Preserve IDs, URLs, files and all historical operations. Only infer success
        // from an existing link; a legacy global failure cannot identify its purpose.
        foreach (['preview' => 'cloud_url', 'final' => 'cloud_final_url'] as $kind => $url) {
            DB::table('galleries')->where($kind.'_share_status', 'offline_only')->whereNotNull($url)->where($url, '!=', '')->update([
                $kind.'_share_status' => 'online',
                $kind.'_shared_at' => DB::raw('last_synced_at'),
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally retain sharing metadata and legacy links on rollback.
        // Rolling back application code must never discard a customer's URLs.
    }
};
