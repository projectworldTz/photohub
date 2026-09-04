<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('slug');
            $t->timestamps();
            $t->unique(['business_id', 'slug']);
        });
        Schema::create('portfolio_photos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('portfolio_category_id')->constrained()->cascadeOnDelete();
            $t->foreignId('photo_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('position')->default(0);
            $t->timestamps();
            $t->unique(['portfolio_category_id', 'photo_id']);
        });
        Schema::create('support_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('subject');
            $t->text('message');
            $t->string('status')->default('open');
            $t->timestamps();
        });
        Schema::create('platform_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->json('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('support_requests');
        Schema::dropIfExists('portfolio_photos');
        Schema::dropIfExists('portfolio_categories');
    }
};
