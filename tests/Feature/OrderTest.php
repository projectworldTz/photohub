<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_calculates_bulk_discount(): void
    {
        $this->seed();
        $b = Business::first();
        $g = Gallery::create(['business_id' => $b->id, 'gallery_number' => 'GAL-1', 'name' => 'Event', 'code' => 'EVENT1', 'type' => 'public_event', 'privacy' => 'public', 'photo_price' => 1000]);
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = Photo::create(['business_id' => $b->id, 'gallery_id' => $g->id, 'uuid' => fake()->uuid(), 'filename' => 'x.jpg', 'original_path' => 'x', 'file_size' => 1, 'mime_type' => 'image/jpeg', 'status' => 'ready'])->id;
        }$o = app(OrderService::class)->create($g, $ids, null);
        $this->assertSame('5000.00', $o->subtotal);
        $this->assertSame('500.00', $o->discount);
        $this->assertSame('4500.00', $o->total);
    }

    public function test_order_payment_creates_ledger_payment_and_receipt(): void
    {
        $this->seed();
        $business = Business::first();
        $gallery = Gallery::create(['business_id' => $business->id, 'gallery_number' => 'GAL-PAY', 'name' => 'Paid Event', 'code' => 'PAYEVENT', 'type' => 'public_event', 'privacy' => 'public', 'photo_price' => 1000]);
        $photo = Photo::create(['business_id' => $business->id, 'gallery_id' => $gallery->id, 'uuid' => fake()->uuid(), 'filename' => 'x.jpg', 'original_path' => 'x', 'file_size' => 1, 'mime_type' => 'image/jpeg', 'status' => 'ready']);
        $order = app(OrderService::class)->create($gallery, [$photo->id], null);
        $user = User::where('email', 'owner@example.com')->first();

        $payment = app(PaymentService::class)->payOrder($order, ['amount' => 1000, 'method' => 'cash', 'payment_date' => now(), 'received_by' => $user->id]);

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertNotNull($payment->receipt);
    }
}
