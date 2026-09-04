<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationAndPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_photographer_is_denied_financial_pages(): void
    {
        $this->seed();
        $business = Business::first();
        $photographer = User::where('email', 'photographer@example.com')->firstOrFail();

        $this->actingAs($photographer)->withSession(['business_id' => $business->id])->get(route('invoices.index'))->assertForbidden();
        $this->get(route('galleries.index'))->assertOk();
    }

    public function test_invoice_pdf_is_generated_and_tenant_protected(): void
    {
        $this->seed();
        $business = Business::first();
        $customer = Customer::first();
        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $invoice = app(InvoiceService::class)->create(['business_id' => $business->id, 'customer_id' => $customer->id, 'discount' => 0, 'tax' => 0, 'due_date' => today()], [['description' => 'Wedding photography', 'quantity' => 1, 'unit_price' => 100000]]);

        $response = $this->actingAs($owner)->withSession(['business_id' => $business->id])->get(route('invoices.pdf', $invoice));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());

        $other = Business::create(['name' => 'Other', 'slug' => 'other-pdf', 'email' => 'other-pdf@example.com']);
        $foreign = Invoice::create(['business_id' => $other->id, 'invoice_number' => 'INV-X', 'customer_id' => $customer->id, 'subtotal' => 1, 'total' => 1, 'balance' => 1, 'due_date' => today(), 'status' => 'unpaid']);
        $this->get(route('invoices.pdf', $foreign))->assertNotFound();
    }
}
