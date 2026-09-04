<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Expense;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Shoot;
use Illuminate\Support\Facades\Cache;

class DashboardAnalyticsService
{
    public function get(int $businessId): array
    {
        return Cache::remember("dashboard-analytics:{$businessId}", now()->addMinutes(5), function () use ($businessId) {
            $months = collect(range(5, 0))->map(fn ($offset) => now()->startOfMonth()->subMonths($offset));
            $monthly = $months->map(fn ($month) => [
                'label' => $month->format('M'),
                'revenue' => (float) Payment::forBusiness($businessId)->where('status', 'completed')->whereBetween('payment_date', [$month, $month->copy()->endOfMonth()])->sum('amount'),
                'expenses' => (float) Expense::forBusiness($businessId)->whereBetween('date', [$month, $month->copy()->endOfMonth()])->sum('amount'),
                'bookings' => Booking::forBusiness($businessId)->whereBetween('event_date', [$month, $month->copy()->endOfMonth()])->count(),
            ]);

            return [
                'monthly' => $monthly,
                'maxMoney' => max(1, (float) $monthly->max(fn ($row) => max($row['revenue'], $row['expenses']))),
                'maxBookings' => max(1, (int) $monthly->max('bookings')),
                'bookingStatuses' => Booking::forBusiness($businessId)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
                'paymentStatuses' => Booking::forBusiness($businessId)->selectRaw('payment_status, count(*) as total')->groupBy('payment_status')->pluck('total', 'payment_status'),
                'packages' => Package::forBusiness($businessId)->withCount('bookings')->orderByDesc('bookings_count')->limit(5)->get(),
                'deliveries' => [
                    'overdue' => Shoot::forBusiness($businessId)->whereNotIn('status', ['delivered'])->whereDate('expected_delivery_date', '<', today())->count(),
                    'dueSoon' => Shoot::forBusiness($businessId)->whereNotIn('status', ['delivered'])->whereBetween('expected_delivery_date', [today(), today()->addDays(3)])->count(),
                    'onTime' => Shoot::forBusiness($businessId)->whereNotIn('status', ['delivered'])->whereDate('expected_delivery_date', '>', today()->addDays(3))->count(),
                ],
            ];
        });
    }
}
