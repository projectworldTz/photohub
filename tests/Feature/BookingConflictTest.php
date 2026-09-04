<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingConflictTest extends TestCase
{
    use RefreshDatabase;

    public function test_photographer_cannot_be_double_booked(): void
    {
        $this->seed();
        $b = Business::where('slug', 'lenscraft-studio')->first();
        $u = User::where('email', 'owner@example.com')->first();
        $c = Customer::create(['business_id' => $b->id, 'customer_number' => 'CUS-000002', 'first_name' => 'A', 'last_name' => 'B', 'phone' => '1']);
        $staff = $u->membershipFor($b->id);
        $existingBookings = Booking::where('business_id', $b->id)->count();
        $payload = ['customer_id' => $c->id, 'event_type' => 'Wedding', 'event_date' => '2026-10-01', 'start_time' => '10:00', 'end_time' => '12:00', 'location' => 'Arusha', 'total_cost' => 1000, 'deposit' => 0, 'status' => 'confirmed', 'staff_ids' => [$staff->id]];
        $this->actingAs($u)->withSession(['business_id' => $b->id])->post('/bookings', $payload)->assertRedirect();
        $this->actingAs($u)->withSession(['business_id' => $b->id])->post('/bookings', $payload + ['start_time' => '11:00', 'end_time' => '13:00'])->assertSessionHasErrors(['staff_ids']);
        $this->assertSame($existingBookings + 1, Booking::where('business_id', $b->id)->count());
    }
}
