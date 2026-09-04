<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('customer_number');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone');
            $table->string('whatsapp')->nullable();
            $table->string('email')->nullable();
            $table->string('gender')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('active')->index();
            $table->boolean('face_search_enabled')->default(false);
            $table->timestamp('customer_consent_at')->nullable();
            $table->string('face_reference')->nullable();
            $table->uuid('face_identifier')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'customer_number']);
            $table->index(['business_id', 'last_name']);
            $table->index(['business_id', 'email']);
        });
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('event_type')->nullable();
            $table->date('event_date')->nullable();
            $table->decimal('estimated_budget', 14, 2)->nullable();
            $table->text('message')->nullable();
            $table->string('source')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('new')->index();
            $table->foreignId('converted_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_at']);
        });
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category');
            $table->text('description')->nullable();
            $table->decimal('price', 14, 2);
            $table->decimal('deposit_amount', 14, 2)->default(0);
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->unsignedSmallInteger('photographers_count')->default(1);
            $table->unsignedInteger('photos_count')->nullable();
            $table->unsignedInteger('edited_photos_count')->nullable();
            $table->boolean('album_included')->default(false);
            $table->boolean('video_included')->default(false);
            $table->boolean('drone_included')->default(false);
            $table->boolean('prints_included')->default(false);
            $table->unsignedSmallInteger('delivery_days')->default(14);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'name']);
            $table->index(['business_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('customers');
    }
};
