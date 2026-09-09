<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            // NULL means legacy storage must be reconciled before enforcing a quota.
            $table->unsignedBigInteger('storage_limit_bytes')->nullable()->default(3221225472);
        });
        DB::table('businesses')->update(['storage_limit_bytes' => null]);
        Schema::create('storage_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained();
            $table->string('path', 1024);
            $table->char('path_hash', 64)->unique();
            $table->unsignedBigInteger('size_bytes');
            $table->index('business_id');
        });
        Schema::table('galleries', function (Blueprint $table) {
            $table->timestamp('expired_at')->nullable()->index();
            $table->unsignedTinyInteger('expiry_notice_days')->nullable();
            $table->index('expires_at');
        });
        // Preserve explicit expiry dates. Give undated existing galleries a full
        // transition window instead of unexpectedly disabling old shared links.
        DB::table('galleries')->whereNull('expires_at')->update(['expires_at' => now()->addDays(30)]);
    }

    public function down(): void
    {
        throw new RuntimeException('This data-preserving migration requires a forward migration to revert; storage allocations and inventory must be retained.');
    }
};
