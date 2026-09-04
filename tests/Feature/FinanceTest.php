<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_totals_partial_payments_and_receipt(): void
    {
        $this->seed();
        $b = Business::first();
        $u = User::where('email', 'owner@example.com')->first();
        $c = Customer::create(['business_id' => $b->id, 'customer_number' => 'CUS-1', 'first_name' => 'Test', 'last_name' => 'Client', 'phone' => '1']);
        $s = app(InvoiceService::class);
        $i = $s->create(['business_id' => $b->id, 'customer_id' => $c->id, 'due_date' => today(), 'discount' => 100, 'tax' => 50], [['description' => 'Shoot', 'quantity' => 2, 'unit_price' => 1000]]);
        $this->assertSame('1950.00', $i->total);
        $p = $s->pay($i, ['amount' => 500, 'method' => 'cash', 'payment_date' => now(), 'received_by' => $u->id]);
        $this->assertSame('1450.00', $i->fresh()->balance);
        $this->assertNotNull($p->receipt);
        $this->expectException(ValidationException::class);
        $s->pay($i->fresh(), ['amount' => 2000, 'method' => 'cash', 'payment_date' => now(), 'received_by' => $u->id]);
    }

    public function test_payment_reversal_restores_invoice_balance_without_deleting_receipt(): void
    {
        $this->seed();
        $business = Business::first();
        $user = User::where('email', 'owner@example.com')->first();
        $customer = Customer::first();
        $invoice = app(InvoiceService::class)->create(['business_id' => $business->id, 'customer_id' => $customer->id, 'due_date' => today()], [['description' => 'Photography', 'quantity' => 1, 'unit_price' => 1000]]);
        $payment = app(InvoiceService::class)->pay($invoice, ['amount' => 1000, 'method' => 'cash', 'payment_date' => now(), 'received_by' => $user->id]);

        app(PaymentService::class)->reverse($payment, 'Incorrect transaction', $user->id);

        $this->assertSame('1000.00', $invoice->fresh()->balance);
        $this->assertSame('reversed', $payment->fresh()->status);
        $this->assertNotNull($payment->receipt);
    }
}
