<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->foreignId('user_id')->nullable()->after('business_id')->constrained()->nullOnDelete());
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('order_number');
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('gallery_id')->constrained()->restrictOnDelete();
            $t->decimal('subtotal', 14, 2);
            $t->decimal('discount', 14, 2)->default(0);
            $t->decimal('total', 14, 2);
            $t->string('payment_status')->default('unpaid');
            $t->string('status')->default('pending');
            $t->timestamps();
            $t->unique(['business_id', 'order_number']);
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('photo_id')->constrained()->restrictOnDelete();
            $t->decimal('price', 14, 2);
            $t->timestamps();
            $t->unique(['order_id', 'photo_id']);
        });
        Schema::create('print_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->string('product');
            $t->string('size');
            $t->unsignedInteger('quantity');
            $t->decimal('total', 14, 2);
            $t->string('status')->default('pending');
            $t->timestamps();
        });
        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('gallery_id')->nullable()->constrained()->cascadeOnDelete();
            $t->text('body');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        Schema::create('reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedTinyInteger('rating');
            $t->text('comment')->nullable();
            $t->boolean('is_public')->default(false);
            $t->timestamps();
        });
        Schema::create('contract_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->longText('body');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('booking_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->longText('content');
            $t->string('status')->default('pending');
            $t->timestamp('accepted_at')->nullable();
            $t->string('accepted_name')->nullable();
            $t->string('accepted_ip', 45)->nullable();
            $t->timestamps();
        });
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();
            $t->dateTime('due_at')->nullable();
            $t->string('priority')->default('normal');
            $t->string('status')->default('todo');
            $t->timestamps();
        });
        Schema::create('subscription_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->decimal('price', 14, 2);
            $t->unsignedBigInteger('storage_limit_mb');
            $t->unsignedInteger('gallery_limit');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->string('status')->default('active');
            $t->boolean('complimentary')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['subscriptions', 'subscription_plans', 'tasks', 'contracts', 'contract_templates', 'reviews', 'messages', 'print_orders', 'order_items', 'orders'] as $x) {
            Schema::dropIfExists($x);
        }Schema::table('customers', fn (Blueprint $t) => $t->dropConstrainedForeignId('user_id'));
    }
};
