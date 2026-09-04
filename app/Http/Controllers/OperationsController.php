<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\PrintOrder;
use App\Models\Review;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OperationsController extends Controller
{
    public function orders(): View
    {
        return view('operations.orders', ['orders' => Order::with(['gallery', 'payments.receipt'])->withCount('items')->forBusiness(app('currentBusiness')->id)->latest()->paginate(30)]);
    }

    public function orderStatus(Request $r, Order $order): RedirectResponse
    {
        $this->guard($order);
        $status = $r->validate(['status' => 'required|in:pending,awaiting_payment,paid,processing,completed,cancelled'])['status'];
        if (in_array($status, ['paid', 'processing', 'completed'], true) && $order->payment_status !== 'paid') {
            return back()->withErrors(['status' => 'Record the order payment before advancing it to this status.']);
        }
        $order->update(['status' => $status]);

        return back();
    }

    public function orderPayment(Request $request, Order $order, PaymentService $service): RedirectResponse
    {
        $this->guard($order);
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'method' => 'required|in:cash,bank_transfer,mpesa,airtel_money,mixx,halopesa,card,other', 'transaction_reference' => 'nullable|string|max:150', 'payment_date' => 'required|date']);
        $service->payOrder($order, $data + ['received_by' => auth()->id()]);

        return back()->with('success', 'Order payment and receipt recorded.');
    }

    public function prints(): View
    {
        return view('operations.prints', ['orders' => PrintOrder::forBusiness(app('currentBusiness')->id)->latest()->paginate(30), 'customers' => Customer::forBusiness(app('currentBusiness')->id)->get()]);
    }

    public function printStore(Request $r): RedirectResponse
    {
        $bid = app('currentBusiness')->id;
        $d = $r->validate(['customer_id' => ['required', Rule::exists('customers', 'id')->where('business_id', $bid)], 'product' => 'required|in:printed_photo,album,frame,canvas,graduation_book', 'size' => 'required|string|max:50', 'quantity' => 'required|integer|min:1', 'total' => 'required|numeric|min:0']);
        PrintOrder::create($d + ['business_id' => $bid, 'status' => 'pending']);

        return back()->with('success', 'Print order created.');
    }

    public function printStatus(Request $r, PrintOrder $printOrder): RedirectResponse
    {
        $this->guard($printOrder);
        $printOrder->update($r->validate(['status' => 'required|in:pending,designing,printing,ready,delivered,cancelled']));

        return back()->with('success', 'Print order updated.');
    }

    public function messages(): View
    {
        $businessId = app('currentBusiness')->id;

        return view('operations.messages', ['messages' => Message::forBusiness($businessId)->latest()->paginate(40), 'customers' => Customer::forBusiness($businessId)->orderBy('first_name')->get()]);
    }

    public function message(Request $r): RedirectResponse
    {
        $businessId = app('currentBusiness')->id;
        $data = $r->validate(['customer_id' => ['required', Rule::exists('customers', 'id')->where('business_id', $businessId)], 'body' => 'required|string|max:5000']);
        Message::create($data + ['business_id' => $businessId, 'sender_id' => auth()->id()]);

        return back()->with('success', 'Reply sent.');
    }

    public function contracts(): View
    {
        $businessId = app('currentBusiness')->id;

        return view('operations.contracts', ['contracts' => Contract::forBusiness($businessId)->latest()->paginate(30), 'templates' => ContractTemplate::forBusiness($businessId)->get(), 'bookings' => Booking::with(['customer', 'package'])->forBusiness($businessId)->latest()->get()]);
    }

    public function template(Request $r): RedirectResponse
    {
        ContractTemplate::create($r->validate(['name' => 'required|string|max:150', 'body' => 'required|string']) + ['business_id' => app('currentBusiness')->id]);

        return back();
    }

    public function contract(Request $r): RedirectResponse
    {
        $business = app('currentBusiness');
        $data = $r->validate(['booking_id' => ['required', Rule::exists('bookings', 'id')->where('business_id', $business->id)], 'template_id' => ['required', Rule::exists('contract_templates', 'id')->where('business_id', $business->id)]]);
        $booking = Booking::with(['customer', 'package'])->findOrFail($data['booking_id']);
        $template = ContractTemplate::findOrFail($data['template_id']);
        $replace = ['{{customer_name}}' => $booking->customer->full_name, '{{business_name}}' => $business->name, '{{event_date}}' => $booking->event_date->format('F j, Y'), '{{event_location}}' => $booking->location, '{{package_name}}' => $booking->package?->name ?? 'Custom', '{{total_amount}}' => $booking->total_cost, '{{deposit}}' => $booking->deposit, '{{balance}}' => $booking->balance];
        Contract::create(['business_id' => $business->id, 'booking_id' => $booking->id, 'customer_id' => $booking->customer_id, 'content' => strtr($template->body, $replace), 'status' => 'pending']);

        return back()->with('success', 'Contract generated.');
    }

    public function reviews(): View
    {
        return view('operations.reviews', ['reviews' => Review::forBusiness(app('currentBusiness')->id)->latest()->paginate(30)]);
    }

    public function review(Request $r, Review $review): RedirectResponse
    {
        $this->guard($review);
        $review->update(['is_public' => $r->boolean('is_public')]);

        return back();
    }

    private function guard($m): void
    {
        abort_unless($m->business_id === app('currentBusiness')->id, 404);
    }
}
