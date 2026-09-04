<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('galleries', fn (Blueprint $table) => $table->boolean('face_search_enabled')->default(false));
        Schema::table('photos', fn (Blueprint $table) => $table->string('face_identifier')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('photos', fn (Blueprint $table) => $table->dropColumn('face_identifier'));
        Schema::table('galleries', fn (Blueprint $table) => $table->dropColumn('face_search_enabled'));
    }
};
