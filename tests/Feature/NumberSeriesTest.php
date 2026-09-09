<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Shoot;
use App\Models\User;
use App\Services\NumberSeriesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NumberSeriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_can_be_converted_to_a_shoot_and_retried_without_duplicates(): void
    {
        $this->seed();
        $business = Business::where('slug', 'lenscraft-studio')->firstOrFail();
        $source = Booking::forBusiness($business->id)->firstOrFail();
        $booking = $source->replicate();
        $booking->booking_number = 'BK-'.now()->year.'-000002';
        $booking->save();
        $booking->staff()->sync($source->staff->mapWithKeys(fn ($staff) => [$staff->id => ['assignment_role' => $staff->pivot->assignment_role]])->all());
        $expectedNumber = 'SHT-'.now()->year.'-000002';

        $this->actingAs(User::where('email', 'owner@example.com')->firstOrFail())->withSession(['business_id' => $business->id]);
        $response = $this->post(route('bookings.shoot', $booking));
        $shoot = Shoot::where('booking_id', $booking->id)->firstOrFail();
        $response->assertRedirect(route('shoots.show', $shoot));
        $this->assertSame($expectedNumber, $shoot->shoot_number);
        $this->assertSame($booking->customer_id, $shoot->customer_id);
        $this->assertEquals($booking->staff->pluck('id')->sort()->values(), $shoot->staff->pluck('id')->sort()->values());
        $this->post(route('bookings.shoot', $booking))->assertRedirect(route('shoots.show', $shoot));
        $this->assertSame(1, Shoot::where('booking_id', $booking->id)->count());
    }

    public function test_number_series_includes_soft_deleted_records_and_remains_scoped_to_studio(): void
    {
        $this->seed();
        $business = Business::where('slug', 'lenscraft-studio')->firstOrFail();
        $source = Booking::forBusiness($business->id)->firstOrFail();
        $booking = $source->replicate();
        $booking->booking_number = 'BK-'.now()->year.'-000010';
        $booking->save();
        $booking->delete();
        $numbers = app(NumberSeriesService::class);
        $this->assertSame('BK-'.now()->year.'-000011', $numbers->next(Booking::class, $business->id, 'booking_number', 'BK', true));

        $other = Business::create(['name' => 'Another studio', 'slug' => 'number-series-other', 'email' => 'other@example.test']);
        $this->assertSame('SHT-'.now()->year.'-000001', $numbers->next(Shoot::class, $other->id, 'shoot_number', 'SHT', true));
        $this->assertSame('SHT-'.now()->year.'-000002', $numbers->next(Shoot::class, $business->id, 'shoot_number', 'SHT', true));
    }
}
