<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Shoot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function save(array $data, ?Booking $booking = null): Booking
    {
        return DB::transaction(function () use ($data, $booking) {
            $staff = $data['staff_ids'] ?? [];
            unset($data['staff_ids']);
            $this->assertAvailable($data['business_id'], $data['event_date'], $data['start_time'], $data['end_time'], $staff, $booking?->id);
            $data['balance'] = max(0, (float) $data['total_cost'] - (float) $data['deposit']);
            $data['payment_status'] = $data['balance'] == 0 ? 'paid' : ($data['deposit'] > 0 ? 'partial' : 'unpaid');
            if ($booking) {
                $booking->update($data);
            } else {
                $data['booking_number'] = app(NumberSeriesService::class)->next(Booking::class, $data['business_id'], 'booking_number', 'BK', true);
                $booking = Booking::create($data);
            }$booking->staff()->sync(collect($staff)->mapWithKeys(fn ($id) => [$id => ['assignment_role' => 'photographer']])->all());

            return $booking;
        });
    }

    public function assertAvailable(int $businessId, string $date, string $start, string $end, array $staffIds, ?int $except = null): void
    {
        $conflict = Booking::forBusiness($businessId)->whereDate('event_date', $date)->when($except, fn ($q) => $q->whereKeyNot($except))->whereNotIn('status', ['cancelled', 'rescheduled'])->where(fn ($q) => $q->where('start_time', '<', $end)->where('end_time', '>', $start))->whereHas('staff', fn ($q) => $q->whereIn('business_user.id', $staffIds))->exists();
        if ($conflict) {
            throw ValidationException::withMessages(['staff_ids' => 'This photographer already has another booking during this time.']);
        }
    }

    public function createShoot(Booking $booking): Shoot
    {
        return DB::transaction(function () use ($booking) {
            $shoot = Shoot::firstOrCreate(['booking_id' => $booking->id], ['business_id' => $booking->business_id, 'shoot_number' => app(NumberSeriesService::class)->next(Shoot::class, $booking->business_id, 'shoot_number', 'SHT', true), 'customer_id' => $booking->customer_id, 'event' => $booking->event_type, 'location' => $booking->location, 'shoot_date' => $booking->event_date, 'start_time' => $booking->start_time, 'end_time' => $booking->end_time, 'notes' => $booking->notes, 'expected_delivery_date' => $booking->expected_delivery_date]);
            $shoot->staff()->sync($booking->staff->mapWithKeys(fn ($m) => [$m->id => ['assignment_role' => $m->pivot->assignment_role]])->all());

            return $shoot;
        });
    }
}
