<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('galleries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('gallery_number');
            $t->string('name');
            $t->string('code')->unique();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->string('event')->nullable();
            $t->text('description')->nullable();
            $t->string('type');
            $t->string('privacy')->default('private');
            $t->string('pin_hash')->nullable();
            $t->dateTime('expires_at')->nullable();
            $t->boolean('downloads_enabled')->default(false);
            $t->boolean('payment_required')->default(false);
            $t->boolean('watermark_enabled')->default(true);
            $t->unsignedInteger('selection_limit')->nullable();
            $t->decimal('extra_photo_price', 14, 2)->default(0);
            $t->decimal('photo_price', 14, 2)->default(0);
            $t->string('status')->default('draft')->index();
            $t->timestamp('selection_completed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['business_id', 'status']);
        });
        Schema::create('photos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('gallery_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid')->unique();
            $t->string('filename');
            $t->string('original_path');
            $t->string('preview_path')->nullable();
            $t->string('thumbnail_path')->nullable();
            $t->unsignedBigInteger('file_size');
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->string('mime_type');
            $t->string('status')->default('processing');
            $t->boolean('is_proof')->default(false);
            $t->boolean('is_final')->default(false);
            $t->boolean('is_downloadable')->default(false);
            $t->boolean('watermarked')->default(false);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['gallery_id', 'status']);
        });
        foreach (['photo_favorites', 'photo_selections'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name) {
                $t->id();
                $t->foreignId('photo_id')->constrained()->cascadeOnDelete();
                $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $t->timestamp($name === 'photo_selections' ? 'selected_at' : 'favorited_at')->useCurrent();
                $t->unique(['photo_id', 'customer_id']);
            });
        }Schema::create('gallery_access', function (Blueprint $t) {
            $t->id();
            $t->foreignId('gallery_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('session_token')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_access');
        Schema::dropIfExists('photo_selections');
        Schema::dropIfExists('photo_favorites');
        Schema::dropIfExists('photos');
        Schema::dropIfExists('galleries');
    }
};
