<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('booking_number');
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $t->string('event_type');
            $t->date('event_date');
            $t->time('start_time');
            $t->time('end_time');
            $t->string('location');
            $t->unsignedInteger('guests')->nullable();
            $t->text('notes')->nullable();
            $t->decimal('total_cost', 14, 2);
            $t->decimal('deposit', 14, 2)->default(0);
            $t->decimal('balance', 14, 2);
            $t->string('payment_status')->default('unpaid')->index();
            $t->string('status')->default('pending')->index();
            $t->date('expected_delivery_date')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['business_id', 'booking_number']);
            $t->index(['business_id', 'event_date']);
        });
        Schema::create('booking_staff', function (Blueprint $t) {
            $t->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $t->foreignId('business_user_id')->constrained('business_user')->cascadeOnDelete();
            $t->string('assignment_role')->default('photographer');
            $t->primary(['booking_id', 'business_user_id']);
        });
        Schema::create('shoots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('shoot_number');
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->string('event');
            $t->string('location');
            $t->date('shoot_date');
            $t->time('start_time');
            $t->time('end_time');
            $t->text('notes')->nullable();
            $t->string('status')->default('planned')->index();
            $t->date('expected_delivery_date')->nullable();
            $t->timestamps();
            $t->unique(['business_id', 'shoot_number']);
        });
        Schema::create('shoot_staff', function (Blueprint $t) {
            $t->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            $t->foreignId('business_user_id')->constrained('business_user')->cascadeOnDelete();
            $t->string('assignment_role')->default('photographer');
            $t->primary(['shoot_id', 'business_user_id']);
        });
        Schema::create('equipment', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('equipment_code');
            $t->string('name');
            $t->string('type');
            $t->string('brand')->nullable();
            $t->string('model')->nullable();
            $t->string('serial_number')->nullable();
            $t->date('purchase_date')->nullable();
            $t->decimal('cost', 14, 2)->nullable();
            $t->string('condition')->default('good');
            $t->string('status')->default('available')->index();
            $t->timestamps();
            $t->unique(['business_id', 'equipment_code']);
            $t->unique(['business_id', 'serial_number']);
        });
        Schema::create('equipment_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $t->foreignId('shoot_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('business_user_id')->nullable()->constrained('business_user')->cascadeOnDelete();
            $t->timestamp('assigned_at');
            $t->timestamp('returned_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('equipment_maintenance', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $t->date('maintenance_date');
            $t->text('problem');
            $t->string('repair_company')->nullable();
            $t->decimal('cost', 14, 2)->default(0);
            $t->date('next_maintenance_date')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_maintenance');
        Schema::dropIfExists('equipment_assignments');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('shoot_staff');
        Schema::dropIfExists('shoots');
        Schema::dropIfExists('booking_staff');
        Schema::dropIfExists('bookings');
    }
};
