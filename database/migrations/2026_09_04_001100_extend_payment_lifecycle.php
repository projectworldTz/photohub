<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('invoice_id')->constrained()->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('invoice_id')->nullable()->change();
            $table->foreignId('customer_id')->nullable()->change();
        });
        Schema::table('receipts', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('invoice_id')->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->change();
            $table->foreignId('customer_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('receipts', fn (Blueprint $table) => $table->dropConstrainedForeignId('order_id'));
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
    }
};
