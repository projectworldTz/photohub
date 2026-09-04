<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeadRequest;
use App\Models\Lead;
use App\Services\LeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeadController extends Controller
{
    public function index(Request $r): View
    {
        $q = Lead::forBusiness(app('currentBusiness')->id)->latest();
        if ($s = $r->string('search')->trim()->value()) {
            $q->where(fn ($x) => $x->where('name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%"));
        }if ($r->filled('status')) {
            $q->where('status', $r->status);
        }

        return view('leads.index', ['leads' => $q->paginate(20)->withQueryString()]);
    }

    public function create(): View
    {
        return view('leads.form', ['lead' => new Lead]);
    }

    public function store(LeadRequest $r): RedirectResponse
    {
        $lead = Lead::create($r->validated() + ['business_id' => app('currentBusiness')->id]);

        return redirect()->route('leads.edit', $lead)->with('success', 'Lead created.');
    }

    public function edit(Lead $lead): View
    {
        $this->guard($lead);

        return view('leads.form', compact('lead'));
    }

    public function update(LeadRequest $r, Lead $lead): RedirectResponse
    {
        $this->guard($lead);
        $lead->update($r->validated());

        return redirect()->route('leads.edit', $lead)->with('success', 'Lead updated.');
    }

    public function destroy(Lead $lead): RedirectResponse
    {
        abort_unless(auth()->user()->hasPermission('customers.manage'), 403);
        $this->guard($lead);
        abort_if($lead->converted_customer_id, 422, 'Converted leads cannot be deleted.');
        $lead->delete();

        return redirect()->route('leads.index')->with('success', 'Lead deleted.');
    }

    public function convert(Lead $lead, LeadService $service): RedirectResponse
    {
        $this->guard($lead);
        $customer = $service->convert($lead, auth()->id());

        return redirect()->route('customers.show', $customer)->with('success', 'Lead converted to customer.');
    }

    private function guard(Lead $m): void
    {
        abort_unless($m->business_id === app('currentBusiness')->id, 404);
    }
}
