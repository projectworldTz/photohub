<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BusinessUser;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonInterface;

class ReportService
{
    public function financial(int $businessId, CarbonInterface $from, CarbonInterface $to): array
    {
        $revenue = (float) Payment::forBusiness($businessId)->where('status', 'completed')->whereBetween('payment_date', [$from, $to])->sum('amount');
        $expenses = (float) Expense::forBusiness($businessId)->whereBetween('date', [$from, $to])->sum('amount');

        return ['revenue' => $revenue, 'expenses' => $expenses, 'profit' => $revenue - $expenses, 'outstanding' => (float) Invoice::forBusiness($businessId)->whereNotIn('status', ['paid', 'cancelled'])->sum('balance'), 'bookings' => Booking::forBusiness($businessId)->whereBetween('event_date', [$from, $to])->count()];
    }

    public function breakdowns(int $businessId, CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            'customers' => Payment::query()->join('customers', 'customers.id', '=', 'payments.customer_id')->where('payments.business_id', $businessId)->where('payments.status', 'completed')->whereBetween('payments.payment_date', [$from, $to])->selectRaw('customers.first_name, customers.last_name, sum(payments.amount) as total')->groupBy('customers.id', 'customers.first_name', 'customers.last_name')->orderByDesc('total')->limit(10)->get()->each(fn ($row) => $row->label = trim($row->first_name.' '.$row->last_name)),
            'events' => Payment::query()->join('bookings', 'bookings.id', '=', 'payments.booking_id')->where('payments.business_id', $businessId)->where('payments.status', 'completed')->whereBetween('payments.payment_date', [$from, $to])->selectRaw('bookings.event_type as label, sum(payments.amount) as total')->groupBy('bookings.event_type')->orderByDesc('total')->get(),
            'packages' => Booking::query()->join('packages', 'packages.id', '=', 'bookings.package_id')->where('bookings.business_id', $businessId)->whereBetween('bookings.event_date', [$from, $to])->selectRaw('packages.name as label, count(bookings.id) as bookings, sum(bookings.total_cost) as total')->groupBy('packages.id', 'packages.name')->orderByDesc('total')->get(),
            'methods' => Payment::forBusiness($businessId)->where('status', 'completed')->whereBetween('payment_date', [$from, $to])->selectRaw('method as label, sum(amount) as total')->groupBy('method')->orderByDesc('total')->get(),
            'staff' => BusinessUser::with('user')->where('business_id', $businessId)->where('status', 'active')->withCount(['shoots as shoots_assigned' => fn ($q) => $q->whereBetween('shoot_date', [$from, $to]), 'shoots as shoots_completed' => fn ($q) => $q->where('shoots.status', 'completed')->whereBetween('shoot_date', [$from, $to])])->withSum(['bookings as revenue' => fn ($q) => $q->whereBetween('event_date', [$from, $to])], 'total_cost')->get(),
        ];
    }
}
