<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'order_number', 'customer_id', 'gallery_id', 'subtotal', 'discount', 'total', 'payment_status', 'status'];

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
