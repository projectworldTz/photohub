<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookingRequest;
use App\Models\Booking;
use App\Models\BusinessUser;
use App\Models\Customer;
use App\Models\Package;
use App\Services\BookingService;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function calendar(): View
    {
        $events = Booking::with('customer')->forBusiness(app('currentBusiness')->id)->get()->map(fn ($booking) => [
            'title' => $booking->customer->full_name.' — '.$booking->event_type,
            'date' => $booking->event_date->format('Y-m-d'), 'time' => $booking->start_time,
            'url' => route('bookings.show', $booking),
        ]);

        return view('bookings.calendar', compact('events'));
    }

    public function index(Request $r): View
    {
        $q = Booking::with(['customer', 'staff.user'])->forBusiness(app('currentBusiness')->id)->orderBy('event_date');
        if ($r->filled('status')) {
            $q->where('status', $r->status);
        }if ($r->filled('date')) {
            $q->whereDate('event_date', $r->date);
        }

        return view('bookings.index', ['bookings' => $q->paginate(20)->withQueryString()]);
    }

    public function create(): View
    {
        return $this->form(new Booking);
    }

    public function store(BookingRequest $r, BookingService $s, NotificationService $notifications): RedirectResponse
    {
        $b = $s->save($r->validated() + ['business_id' => app('currentBusiness')->id]);
        $notifications->business(app('currentBusiness'), 'New booking', $b->booking_number.' was created.', route('bookings.show', $b));

        return redirect()->route('bookings.show', $b)->with('success', 'Booking created.');
    }

    public function show(Booking $booking): View
    {
        $this->guard($booking);

        return view('bookings.show', ['booking' => $booking->load(['customer', 'package', 'staff.user', 'shoot'])]);
    }

    public function edit(Booking $booking): View
    {
        $this->guard($booking);

        return $this->form($booking->load('staff'));
    }

    public function update(BookingRequest $r, Booking $booking, BookingService $s): RedirectResponse
    {
        $this->guard($booking);
        $s->save($r->validated() + ['business_id' => $booking->business_id], $booking);

        return redirect()->route('bookings.show', $booking)->with('success', 'Booking updated.');
    }

    public function convert(Booking $booking, BookingService $s): RedirectResponse
    {
        $this->guard($booking);
        $shoot = $s->createShoot($booking->load('staff'));

        return redirect()->route('shoots.show', $shoot)->with('success', 'Shoot prepared.');
    }

    private function form(Booking $booking): View
    {
        $id = app('currentBusiness')->id;

        return view('bookings.form', compact('booking') + ['customers' => Customer::forBusiness($id)->orderBy('first_name')->get(), 'packages' => Package::forBusiness($id)->where('is_active', true)->get(), 'staff' => BusinessUser::with('user')->where('business_id', $id)->where('status', 'active')->get()]);
    }

    private function guard(Booking $b): void
    {
        abort_unless($b->business_id === app('currentBusiness')->id, 404);
    }
}
