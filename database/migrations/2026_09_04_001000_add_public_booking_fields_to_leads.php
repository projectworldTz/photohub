<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('assigned_user_id')->constrained()->nullOnDelete();
            $table->time('start_time')->nullable()->after('event_date');
            $table->time('end_time')->nullable()->after('start_time');
            $table->index(['business_id', 'event_date', 'start_time']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'event_date', 'start_time']);
            $table->dropConstrainedForeignId('package_id');
            $table->dropColumn(['start_time', 'end_time']);
        });
    }
};
