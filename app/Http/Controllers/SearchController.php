<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Gallery;
use App\Models\Invoice;
use App\Models\Shoot;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function __invoke(Request $request): View
    {
        $term = trim((string) $request->validate(['q' => 'nullable|string|max:100'])['q']);
        $businessId = app('currentBusiness')->id;
        $like = '%'.addcslashes($term, '%_').'%';
        $results = collect();
        if (mb_strlen($term) >= 2) {
            Customer::forBusiness($businessId)->where(fn ($q) => $q->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('customer_number', 'like', $like)->orWhere('phone', 'like', $like))->limit(10)->get()->each(fn ($m) => $results->push(['type' => 'Customer', 'label' => $m->full_name.' · '.$m->customer_number, 'url' => route('customers.show', $m)]));
            Booking::with('customer')->forBusiness($businessId)->where(fn ($q) => $q->where('booking_number', 'like', $like)->orWhere('event_type', 'like', $like)->orWhere('location', 'like', $like))->limit(10)->get()->each(fn ($m) => $results->push(['type' => 'Booking', 'label' => $m->booking_number.' · '.$m->event_type, 'url' => route('bookings.show', $m)]));
            Invoice::with('customer')->forBusiness($businessId)->where('invoice_number', 'like', $like)->limit(10)->get()->each(fn ($m) => $results->push(['type' => 'Invoice', 'label' => $m->invoice_number.' · '.$m->customer->full_name, 'url' => route('invoices.show', $m)]));
            Gallery::forBusiness($businessId)->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('gallery_number', 'like', $like)->orWhere('event', 'like', $like))->limit(10)->get()->each(fn ($m) => $results->push(['type' => 'Gallery', 'label' => $m->name.' · '.$m->gallery_number, 'url' => route('galleries.show', $m)]));
            Shoot::forBusiness($businessId)->where(fn ($q) => $q->where('shoot_number', 'like', $like)->orWhere('event', 'like', $like)->orWhere('location', 'like', $like))->limit(10)->get()->each(fn ($m) => $results->push(['type' => 'Shoot', 'label' => $m->shoot_number.' · '.$m->event, 'url' => route('shoots.show', $m)]));
        }

        return view('search.index', compact('term', 'results'));
    }
}
