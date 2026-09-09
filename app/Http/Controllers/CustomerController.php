<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Services\NumberSeriesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $r): View
    {
        $q = Customer::forBusiness(app('currentBusiness')->id)->latest();
        if ($s = $r->string('search')->trim()->value()) {
            $q->where(fn ($x) => $x->where('first_name', 'like', "%$s%")->orWhere('last_name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")->orWhere('customer_number', 'like', "%$s%"));
        }if ($r->filled('status')) {
            $q->where('status', $r->status);
        }

        return view('customers.index', ['customers' => $q->paginate(20)->withQueryString()]);
    }

    public function create(): View
    {
        return view('customers.form', ['customer' => new Customer]);
    }

    public function store(CustomerRequest $r, NumberSeriesService $numbers): RedirectResponse
    {
        $data = $r->validated();
        $data['business_id'] = app('currentBusiness')->id;
        $data['customer_number'] = $numbers->next(Customer::class, $data['business_id'], 'customer_number', 'CUS');
        $customer = Customer::create($data);
        $this->log('customer.created', $customer);

        return redirect()->route('customers.show', $customer)->with('success', 'Customer created.');
    }

    public function show(Customer $customer): View
    {
        $this->guard($customer);

        $customer->load(['bookings' => fn ($q) => $q->latest('event_date'), 'shoots' => fn ($q) => $q->latest('shoot_date'), 'galleries' => fn ($q) => $q->latest(), 'invoices' => fn ($q) => $q->latest(), 'payments' => fn ($q) => $q->latest('payment_date'), 'orders' => fn ($q) => $q->latest()]);

        return view('customers.show', compact('customer'));
    }

    public function edit(Customer $customer): View
    {
        $this->guard($customer);

        return view('customers.form', compact('customer'));
    }

    public function update(CustomerRequest $r, Customer $customer): RedirectResponse
    {
        $this->guard($customer);
        $customer->update($r->validated());
        $this->log('customer.updated', $customer);

        return redirect()->route('customers.show', $customer)->with('success', 'Customer updated.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        abort_unless(auth()->user()->hasPermission('customers.manage'), 403);
        $this->guard($customer);
        $customer->delete();
        $this->log('customer.archived', $customer);

        return redirect()->route('customers.index')->with('success', 'Customer archived.');
    }

    private function guard(Customer $m): void
    {
        abort_unless($m->business_id === app('currentBusiness')->id, 404);
    }

    private function log(string $action, Customer $m): void
    {
        ActivityLog::create(['business_id' => $m->business_id, 'user_id' => auth()->id(), 'action' => $action, 'subject_type' => Customer::class, 'subject_id' => $m->id, 'ip_address' => request()->ip()]);
    }
}
