<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Gallery;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Models\Review;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalController extends Controller
{
    private function customer(): Customer
    {
        return Customer::where('user_id', auth()->id())->firstOrFail();
    }

    public function index(): View
    {
        $c = $this->customer();

        return view('portal.index', ['customer' => $c, 'bookings' => Booking::where('customer_id', $c->id)->latest()->get(), 'galleries' => Gallery::where('customer_id', $c->id)->latest()->get(), 'invoices' => Invoice::with('payments.receipt')->where('customer_id', $c->id)->latest()->get(), 'payments' => Payment::with('receipt')->where('customer_id', $c->id)->latest('payment_date')->get(), 'orders' => Order::where('customer_id', $c->id)->latest()->get()]);
    }

    public function message(Request $r): RedirectResponse
    {
        $c = $this->customer();
        Message::create(['business_id' => $c->business_id, 'sender_id' => auth()->id(), 'customer_id' => $c->id, 'body' => $r->validate(['body' => 'required|string|max:5000'])['body']]);

        return back()->with('success', 'Message sent.');
    }

    public function review(Request $r): RedirectResponse
    {
        $c = $this->customer();
        $d = $r->validate(['rating' => 'required|integer|min:1|max:5', 'comment' => 'nullable|string|max:2000']);
        Review::create($d + ['business_id' => $c->business_id, 'customer_id' => $c->id]);

        return back()->with('success', 'Thank you for your review.');
    }

    public function accept(Request $r, Contract $contract): RedirectResponse
    {
        $customer = $this->customer();
        abort_unless($contract->customer_id === $customer->id, 404);
        $data = $r->validate(['accepted_name' => 'required|string|max:150', 'agree' => 'accepted']);
        $contract->update(['status' => 'accepted', 'accepted_at' => now(), 'accepted_name' => $data['accepted_name'], 'accepted_ip' => $r->ip()]);

        return back()->with('success', 'Contract accepted.');
    }

    public function invoicePdf(Invoice $invoice)
    {
        $customer = $this->customer();
        abort_unless($invoice->customer_id === $customer->id, 404);

        return Pdf::loadView('documents.financial', ['type' => 'Invoice', 'document' => $invoice->load(['customer', 'items']), 'business' => Business::findOrFail($customer->business_id)])->download($invoice->invoice_number.'.pdf');
    }

    public function quotationPdf(Quotation $quotation)
    {
        $customer = $this->customer();
        abort_unless($quotation->customer_id === $customer->id, 404);

        return Pdf::loadView('documents.financial', ['type' => 'Quotation', 'document' => $quotation->load(['customer', 'items']), 'business' => Business::findOrFail($customer->business_id)])->download($quotation->quotation_number.'.pdf');
    }

    public function receiptPdf(Receipt $receipt)
    {
        $customer = $this->customer();
        abort_unless($receipt->customer_id === $customer->id, 404);

        return Pdf::loadView('documents.receipt', ['receipt' => $receipt->load(['customer', 'invoice', 'order', 'payment']), 'business' => Business::findOrFail($customer->business_id)])->download($receipt->receipt_number.'.pdf');
    }
}
