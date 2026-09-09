<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BusinessUser;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ReportService
{
    /** Database daily aggregates keep memory bounded even with many transactions. */
    public function trends(int $businessId, CarbonInterface $from, CarbonInterface $to, string $interval = 'auto'): array
    {
        $start = CarbonImmutable::instance($from)->startOfDay();
        $end = CarbonImmutable::instance($to)->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;
        $interval = $interval === 'auto' ? ($days <= 45 ? 'day' : ($days <= 240 ? 'week' : 'month')) : $interval;
        $previousStart = $start->subDays($days);
        $previousEnd = $start->subDay();
        $payments = Payment::forBusiness($businessId)->where('status', 'completed')->whereBetween('payment_date', [$previousStart, $end->endOfDay()])
            ->selectRaw('DATE(payment_date) as day, SUM(amount) as total')->groupByRaw('DATE(payment_date)')->pluck('total', 'day');
        $expenses = Expense::forBusiness($businessId)->whereBetween('date', [$previousStart->toDateString(), $end->endOfDay()])
            ->selectRaw('DATE(date) as day, SUM(amount) as total')->groupByRaw('DATE(date)')->pluck('total', 'day');
        $bookings = Booking::forBusiness($businessId)->whereBetween('event_date', [$previousStart->toDateString(), $end->endOfDay()])
            ->selectRaw('DATE(event_date) as day, COUNT(*) as total')->groupByRaw('DATE(event_date)')->pluck('total', 'day');

        $series = [];
        $totals = ['revenue' => 0, 'expenses' => 0, 'profit' => 0, 'bookings' => 0];
        $previous = $totals;
        $cumulative = 0;
        for ($cursor = $start; $cursor->lte($end); $cursor = $bucketEnd->addDay()) {
            $bucketEnd = match ($interval) {
                'month' => $cursor->endOfMonth()->startOfDay()->min($end),
                'week' => $cursor->addDays(6)->min($end),
                default => $cursor,
            };
            $offset = (int) $start->diffInDays($cursor);
            $priorStart = $previousStart->addDays($offset);
            $span = (int) $cursor->diffInDays($bucketEnd);
            $current = ['revenue' => 0, 'expenses' => 0, 'bookings' => 0];
            $prior = $current;
            for ($i = 0; $i <= $span; $i++) {
                $date = $cursor->addDays($i)->toDateString();
                $oldDate = $priorStart->addDays($i)->toDateString();
                foreach (['revenue' => $payments, 'expenses' => $expenses, 'bookings' => $bookings] as $key => $values) {
                    $current[$key] += (float) ($values[$date] ?? 0);
                    $prior[$key] += (float) ($values[$oldDate] ?? 0);
                }
            }
            $current['profit'] = $current['revenue'] - $current['expenses'];
            $prior['profit'] = $prior['revenue'] - $prior['expenses'];
            foreach ($totals as $key => $value) {
                $totals[$key] += $current[$key];
                $previous[$key] += $prior[$key];
            }
            $cumulative += $current['profit'];
            $last = $series ? $series[array_key_last($series)]['profit'] : null;
            $recent = array_slice(array_column($series, 'revenue'), -2);
            $recent[] = $current['revenue'];
            $series[] = array_map(fn ($value) => round($value, 2), $current) + [
                'from' => $cursor->toDateString(), 'to' => $bucketEnd->toDateString(),
                'label' => $interval === 'day' ? $cursor->format('d M') : $cursor->format('d M').' – '.$bucketEnd->format('d M'),
                'previous_from' => $priorStart->toDateString(), 'previous_to' => $priorStart->addDays($span)->toDateString(),
                'previous_revenue' => round($prior['revenue'], 2),
                'moving_average' => round(array_sum($recent) / count($recent), 2),
                'cumulative' => round($cumulative, 2),
                'fluctuation' => $last === null ? null : round($current['profit'] - $last, 2),
            ];
        }
        $changes = [];
        foreach ($totals as $key => $value) {
            $delta = $value - $previous[$key];
            $changes[$key] = ['amount' => round($delta, 2), 'percent' => $previous[$key] == 0 ? null : round($delta / abs($previous[$key]) * 100, 1)];
        }
        $peak = collect($series)->sortByDesc('revenue')->first();
        $swings = collect($series)->filter(fn ($row) => $row['fluctuation'] !== null);
        $swing = $swings->sortByDesc(fn ($row) => abs($row['fluctuation']))->first();

        return [
            'interval' => $interval, 'series' => $series, 'totals' => array_map(fn ($v) => round($v, 2), $totals),
            'previous' => array_map(fn ($v) => round($v, 2), $previous), 'changes' => $changes,
            'previous_from' => $previousStart->toDateString(), 'previous_to' => $previousEnd->toDateString(),
            'insights' => [
                'peak' => $peak && $peak['revenue'] > 0 ? $peak : null,
                'swing' => $swing && abs($swing['fluctuation']) > 0 ? $swing : null,
                'average_daily_revenue' => round($totals['revenue'] / $days, 2),
                'margin' => $totals['revenue'] == 0 ? null : round($totals['profit'] / $totals['revenue'] * 100, 1),
                'average_swing' => $swings->isEmpty() ? null : round($swings->avg(fn ($row) => abs($row['fluctuation'])), 2),
            ],
        ];
    }

    public function financial(int $businessId, CarbonInterface $from, CarbonInterface $to): array
    {
        $revenue = (float) Payment::forBusiness($businessId)->where('status', 'completed')->whereBetween('payment_date', [$from, $to])->sum('amount');
        $expenses = (float) Expense::forBusiness($businessId)->whereBetween('date', [$from->toDateString(), $to])->sum('amount');

        return ['revenue' => $revenue, 'expenses' => $expenses, 'profit' => $revenue - $expenses, 'outstanding' => (float) Invoice::forBusiness($businessId)->whereNotIn('status', ['paid', 'cancelled'])->sum('balance'), 'bookings' => Booking::forBusiness($businessId)->whereBetween('event_date', [$from->toDateString(), $to])->count()];
    }

    public function breakdowns(int $businessId, CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            'customers' => Payment::query()->join('customers', 'customers.id', '=', 'payments.customer_id')->where('payments.business_id', $businessId)->where('payments.status', 'completed')->whereBetween('payments.payment_date', [$from, $to])->selectRaw('customers.first_name, customers.last_name, sum(payments.amount) as total')->groupBy('customers.id', 'customers.first_name', 'customers.last_name')->orderByDesc('total')->limit(10)->get()->each(fn ($row) => $row->label = trim($row->first_name.' '.$row->last_name)),
            'events' => Payment::query()->join('bookings', 'bookings.id', '=', 'payments.booking_id')->where('payments.business_id', $businessId)->where('payments.status', 'completed')->whereBetween('payments.payment_date', [$from, $to])->selectRaw('bookings.event_type as label, sum(payments.amount) as total')->groupBy('bookings.event_type')->orderByDesc('total')->get(),
            'packages' => Booking::query()->join('packages', 'packages.id', '=', 'bookings.package_id')->where('bookings.business_id', $businessId)->whereBetween('bookings.event_date', [$from->toDateString(), $to])->selectRaw('packages.name as label, count(bookings.id) as bookings, sum(bookings.total_cost) as total')->groupBy('packages.id', 'packages.name')->orderByDesc('total')->get(),
            'methods' => Payment::forBusiness($businessId)->where('status', 'completed')->whereBetween('payment_date', [$from, $to])->selectRaw('method as label, sum(amount) as total')->groupBy('method')->orderByDesc('total')->get(),
            'staff' => BusinessUser::with('user')->where('business_id', $businessId)->where('status', 'active')->withCount(['shoots as shoots_assigned' => fn ($q) => $q->whereBetween('shoot_date', [$from->toDateString(), $to]), 'shoots as shoots_completed' => fn ($q) => $q->where('shoots.status', 'completed')->whereBetween('shoot_date', [$from->toDateString(), $to])])->withSum(['bookings as revenue' => fn ($q) => $q->whereBetween('event_date', [$from->toDateString(), $to])], 'total_cost')->get(),
        ];
    }
}
