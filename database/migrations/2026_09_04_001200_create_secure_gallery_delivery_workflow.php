<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->foreignId('shoot_id')->nullable()->after('booking_id')->constrained()->nullOnDelete();
            $table->date('event_date')->nullable()->after('event');
            $table->boolean('require_exact_selection')->default(false)->after('selection_limit');
            $table->unsignedInteger('submitted_selection_count')->nullable()->after('selection_completed_at');
            $table->index(['business_id', 'type', 'status']);
        });
        Schema::create('gallery_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 30);
            $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->timestamp('generated_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamp('notified_30_at')->nullable();
            $table->timestamp('notified_7_at')->nullable();
            $table->timestamp('notified_1_at')->nullable();
            $table->timestamps();
            $table->index(['gallery_id', 'purpose', 'revoked_at'], 'gallery_token_lookup');
        });
        Schema::create('final_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gallery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proof_photo_id')->nullable()->constrained('photos')->nullOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('filename');
            $table->string('original_path');
            $table->string('preview_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('mime_type');
            $table->string('status')->default('ready')->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['gallery_id', 'proof_photo_id']);
            $table->index(['gallery_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_photos');
        Schema::dropIfExists('gallery_access_tokens');
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropForeign(['shoot_id']);
            $table->dropIndex(['business_id', 'type', 'status']);
            $table->dropColumn(['shoot_id', 'event_date', 'require_exact_selection', 'submitted_selection_count']);
        });
    }
};
