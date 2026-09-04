<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\Order;
use App\Models\Photo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function create(Gallery $g, array $photoIds, ?int $customerId): Order
    {
        return DB::transaction(function () use ($g, $photoIds, $customerId) {
            $ids = array_values(array_unique($photoIds));
            $photos = Photo::where('gallery_id', $g->id)->whereIn('id', $ids)->get();
            if ($photos->count() !== count($ids) || ! count($ids)) {
                throw ValidationException::withMessages(['photos' => 'Choose valid photos from this gallery.']);
            }$subtotal = $photos->count() * (float) $g->photo_price;
            $discount = $this->discount($photos->count(), $subtotal);
            $order = Order::create(['business_id' => $g->business_id, 'order_number' => 'ORD-'.now()->format('Y').'-'.str_pad((string) ((Order::forBusiness($g->business_id)->max('id') ?? 0) + 1), 6, '0', STR_PAD_LEFT), 'customer_id' => $customerId, 'gallery_id' => $g->id, 'subtotal' => $subtotal, 'discount' => $discount, 'total' => $subtotal - $discount, 'payment_status' => 'unpaid', 'status' => 'awaiting_payment']);
            $order->items()->createMany($photos->map(fn ($p) => ['photo_id' => $p->id, 'price' => $g->photo_price])->all());

            return $order;
        });
    }

    private function discount(int $quantity, float $subtotal): float
    {
        return $quantity >= 10 ? $subtotal * .15 : ($quantity >= 5 ? $subtotal * .1 : 0);
    }
}
