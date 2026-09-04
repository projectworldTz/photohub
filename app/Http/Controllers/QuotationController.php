<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Services\QuotationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class QuotationController extends Controller
{
    public function index(): View
    {
        return view('quotations.index', ['quotations' => Quotation::with('customer')->forBusiness(app('currentBusiness')->id)->latest()->paginate(20)]);
    }

    public function create(): View
    {
        $businessId = app('currentBusiness')->id;

        return view('quotations.form', ['customers' => Customer::forBusiness($businessId)->get(), 'bookings' => Booking::with('customer')->forBusiness($businessId)->latest()->get()]);
    }

    public function store(Request $r, QuotationService $s): RedirectResponse
    {
        $bid = app('currentBusiness')->id;
        $d = $r->validate(['customer_id' => ['required', Rule::exists('customers', 'id')->where('business_id', $bid)], 'booking_id' => ['nullable', Rule::exists('bookings', 'id')->where('business_id', $bid)], 'date' => 'required|date', 'expiry_date' => 'required|date|after_or_equal:date', 'discount' => 'nullable|numeric|min:0', 'tax' => 'nullable|numeric|min:0', 'notes' => 'nullable|string', 'terms' => 'nullable|string', 'items' => 'required|array|min:1', 'items.*.description' => 'required|string', 'items.*.quantity' => 'required|numeric|min:.01', 'items.*.unit_price' => 'required|numeric|min:0']);
        if (! empty($d['booking_id']) && Booking::findOrFail($d['booking_id'])->customer_id !== (int) $d['customer_id']) {
            return back()->withInput()->withErrors(['booking_id' => 'The booking must belong to the selected customer.']);
        }
        $items = $d['items'];
        unset($d['items']);
        $q = $s->create($d + ['business_id' => $bid], $items);

        return redirect()->route('quotations.show', $q);
    }

    public function show(Quotation $quotation): View
    {
        $this->guard($quotation);

        return view('quotations.show', ['quotation' => $quotation->load(['customer', 'items', 'invoice'])]);
    }

    public function convert(Quotation $quotation, QuotationService $s): RedirectResponse
    {
        $this->guard($quotation);

        return redirect()->route('invoices.show', $s->convert($quotation->load('items')));
    }

    public function status(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->guard($quotation);
        abort_if($quotation->invoice_id, 422, 'Converted quotations cannot be changed.');
        $quotation->update($request->validate(['status' => 'required|in:draft,sent,accepted,rejected,expired']));

        return back()->with('success', 'Quotation status updated.');
    }

    private function guard(Quotation $q): void
    {
        abort_unless($q->business_id === app('currentBusiness')->id, 404);
    }
}
