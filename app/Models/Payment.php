<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'customer_id', 'booking_id', 'invoice_id', 'order_id', 'amount', 'method', 'transaction_reference', 'payment_date', 'received_by', 'status', 'notes', 'reversed_at', 'reversed_by', 'reversal_reason'];

    protected function casts(): array
    {
        return ['payment_date' => 'datetime', 'reversed_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
