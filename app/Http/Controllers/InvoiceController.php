<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(): View
    {
        return view('invoices.index', ['invoices' => Invoice::with('customer')->forBusiness(app('currentBusiness')->id)->latest()->paginate(20)]);
    }

    public function create(): View
    {
        $businessId = app('currentBusiness')->id;

        return view('invoices.form', ['customers' => Customer::forBusiness($businessId)->get(), 'bookings' => Booking::with('customer')->forBusiness($businessId)->latest()->get()]);
    }

    public function store(Request $r, InvoiceService $s): RedirectResponse
    {
        $bid = app('currentBusiness')->id;
        $data = $r->validate(['customer_id' => ['required', Rule::exists('customers', 'id')->where('business_id', $bid)], 'booking_id' => ['nullable', Rule::exists('bookings', 'id')->where('business_id', $bid)], 'due_date' => 'required|date', 'discount' => 'nullable|numeric|min:0', 'tax' => 'nullable|numeric|min:0', 'notes' => 'nullable|string', 'items' => 'required|array|min:1', 'items.*.description' => 'required|string', 'items.*.quantity' => 'required|numeric|min:0.01', 'items.*.unit_price' => 'required|numeric|min:0']);
        if (! empty($data['booking_id']) && Booking::findOrFail($data['booking_id'])->customer_id !== (int) $data['customer_id']) {
            return back()->withInput()->withErrors(['booking_id' => 'The booking must belong to the selected customer.']);
        }
        $items = $data['items'];
        unset($data['items']);
        $i = $s->create($data + ['business_id' => $bid], $items);

        return redirect()->route('invoices.show', $i);
    }

    public function show(Invoice $invoice): View
    {
        $this->guard($invoice);

        return view('invoices.show', ['invoice' => $invoice->load(['customer', 'items', 'payments.receipt'])]);
    }

    public function payment(Request $r, Invoice $invoice, InvoiceService $s): RedirectResponse
    {
        $this->guard($invoice);
        $data = $r->validate(['amount' => 'required|numeric|min:0.01', 'method' => 'required|in:cash,bank_transfer,mpesa,airtel_money,mixx,halopesa,card,other', 'transaction_reference' => 'nullable|string|max:150', 'payment_date' => 'required|date', 'notes' => 'nullable|string']);
        $s->pay($invoice, $data + ['received_by' => auth()->id()]);

        return back()->with('success', 'Payment recorded and receipt generated.');
    }

    public function reversePayment(Request $request, Payment $payment, PaymentService $service): RedirectResponse
    {
        abort_unless($payment->business_id === app('currentBusiness')->id, 404);
        $reason = $request->validate(['reason' => 'required|string|min:5|max:1000'])['reason'];
        $service->reverse($payment, $reason, auth()->id());

        return back()->with('success', 'Payment reversed. The original transaction remains in the audit history.');
    }

    public function status(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->guard($invoice);
        $status = $request->validate(['status' => 'required|in:draft,unpaid,cancelled'])['status'];
        abort_if($status === 'cancelled' && $invoice->payments()->where('status', 'completed')->exists(), 422, 'Reverse completed payments before cancelling this invoice.');
        $invoice->update(['status' => $status]);

        return back()->with('success', 'Invoice status updated.');
    }

    private function guard(Invoice $i): void
    {
        abort_unless($i->business_id === app('currentBusiness')->id, 404);
    }
}
