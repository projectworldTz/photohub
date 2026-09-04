<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->default('Tanzania');
            $table->string('logo_path')->nullable();
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('currency', 3)->default('TZS');
            $table->string('timezone')->default('Africa/Dar_es_Salaam');
            $table->string('status')->default('active')->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('group')->index();
            $table->timestamps();
        });

        Schema::create('business_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('employee_number')->nullable();
            $table->string('job_title')->nullable();
            $table->date('joined_at')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->unique(['business_id', 'user_id']);
            $table->unique(['business_id', 'employee_number']);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('business_user_role', function (Blueprint $table) {
            $table->foreignId('business_user_id')->constrained('business_user')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['business_user_id', 'role_id']);
        });

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'key']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->nullableMorphs('subject');
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('business_settings');
        Schema::dropIfExists('business_user_role');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('business_user');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('businesses');
    }
};
