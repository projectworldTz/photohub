<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('galleries', function (Blueprint $t) {
            $t->uuid('sync_uuid')->nullable()->unique();
            $t->unsignedBigInteger('cloud_gallery_id')->nullable();
            $t->text('cloud_url')->nullable();
            $t->text('cloud_final_url')->nullable();
            $t->string('cloud_status')->default('local_only')->index();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamp('selection_synced_at')->nullable();
            $t->text('sync_error')->nullable();
            $t->boolean('cloud_replica')->default(false);
        });
        foreach (['photos', 'final_photos'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('cloud_photo_id')->nullable();
                $t->string('sync_status')->default('local_only')->index();
                $t->timestamp('synced_at')->nullable();
                $t->timestamp('cloud_uploaded_at')->nullable();
                $t->text('sync_error')->nullable();
            });
        }
        Schema::create('studio_api_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('sync_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('gallery_id')->constrained()->cascadeOnDelete();
            $t->string('type');
            $t->string('status')->default('queued')->index();
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamp('queued_at');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('next_retry_at')->nullable()->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_jobs');
        Schema::dropIfExists('studio_api_tokens');
        foreach (['photos', 'final_photos'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['cloud_photo_id', 'sync_status', 'synced_at', 'cloud_uploaded_at', 'sync_error']));
        }
        Schema::table('galleries', fn (Blueprint $t) => $t->dropColumn(['sync_uuid', 'cloud_gallery_id', 'cloud_url', 'cloud_final_url', 'cloud_status', 'last_synced_at', 'selection_synced_at', 'sync_error', 'cloud_replica']));
    }
};
