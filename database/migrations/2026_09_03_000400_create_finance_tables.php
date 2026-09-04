<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('quotation_number');
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->date('date');
            $t->date('expiry_date');
            $t->decimal('subtotal', 14, 2);
            $t->decimal('discount', 14, 2)->default(0);
            $t->decimal('tax', 14, 2)->default(0);
            $t->decimal('total', 14, 2);
            $t->text('notes')->nullable();
            $t->text('terms')->nullable();
            $t->string('status')->default('draft')->index();
            $t->foreignId('invoice_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['business_id', 'quotation_number']);
        });
        Schema::create('quotation_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $t->string('description');
            $t->decimal('quantity', 10, 2);
            $t->decimal('unit_price', 14, 2);
            $t->decimal('total', 14, 2);
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('invoice_number');
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('subtotal', 14, 2);
            $t->decimal('discount', 14, 2)->default(0);
            $t->decimal('tax', 14, 2)->default(0);
            $t->decimal('total', 14, 2);
            $t->decimal('paid', 14, 2)->default(0);
            $t->decimal('balance', 14, 2);
            $t->date('due_date');
            $t->string('status')->default('unpaid')->index();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['business_id', 'invoice_number']);
        });
        Schema::table('quotations', fn (Blueprint $t) => $t->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete());
        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->string('description');
            $t->decimal('quantity', 10, 2);
            $t->decimal('unit_price', 14, 2);
            $t->decimal('total', 14, 2);
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 14, 2);
            $t->string('method');
            $t->string('transaction_reference')->nullable();
            $t->dateTime('payment_date');
            $t->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $t->string('status')->default('completed');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'payment_date']);
        });
        Schema::create('receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('receipt_number');
            $t->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 14, 2);
            $t->timestamps();
            $t->unique(['business_id', 'receipt_number']);
        });
        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('category');
            $t->decimal('amount', 14, 2);
            $t->text('description');
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->string('receipt_path')->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['business_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::table('quotations', fn (Blueprint $t) => $t->dropForeign(['invoice_id']));
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
