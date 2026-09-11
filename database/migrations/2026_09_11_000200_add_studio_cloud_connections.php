<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('studio_cloud_connections')) {
            Schema::create('studio_cloud_connections', function (Blueprint $t) {
                $t->id();
                $t->foreignId('business_id')->unique()->constrained()->restrictOnDelete();
                $t->uuid('identity_uuid')->unique();
                $t->text('registration_secret');
                $t->text('api_token')->nullable();
                $t->string('base_url')->nullable();
                $t->unsignedBigInteger('cloud_studio_id')->nullable();
                $t->string('status')->default('not_connected');
                $t->timestamp('registered_at')->nullable();
                $t->timestamp('last_connected_at')->nullable();
                $t->text('last_error')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasColumn('businesses', 'local_studio_uuid')) {
            Schema::table('businesses', function (Blueprint $t) {
                $t->uuid('local_studio_uuid')->nullable()->unique();
                $t->boolean('cloud_identity_only')->default(false);
                $t->string('registration_key_hash', 64)->nullable();
                $t->unsignedInteger('credential_generation')->default(1);
                $t->timestamp('cloud_last_seen_at')->nullable();
                $t->string('app_version', 50)->nullable();
            });
        }
        // Backfill every existing local studio without making a network request.
        // The legacy environment token belongs ONLY to its explicitly bound studio.
        if (in_array(config('photohub.mode'), ['local', 'hybrid'], true)) {
            DB::table('businesses')->orderBy('id')->eachById(function ($business) {
                if (DB::table('studio_cloud_connections')->where('business_id', $business->id)->exists()) return;
                $legacy = $business->id === config('photohub.business_id') ? config('photohub.studio_token') : null;
                DB::table('studio_cloud_connections')->insert([
                    'business_id' => $business->id, 'identity_uuid' => (string) Str::uuid(),
                    'registration_secret' => Crypt::encryptString(Str::random(64)),
                    'api_token' => $legacy ? Crypt::encryptString($legacy) : null,
                    'base_url' => config('photohub.cloud_url'), 'status' => 'not_connected',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        }
    }

    public function down(): void
    {
        // Retain identities and credentials: rollback must not orphan online links.
    }
};
