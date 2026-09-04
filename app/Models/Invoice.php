<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use BelongsToBusiness,SoftDeletes;

    protected $fillable = ['business_id', 'invoice_number', 'customer_id', 'booking_id', 'subtotal', 'discount', 'tax', 'total', 'paid', 'balance', 'due_date', 'status', 'notes'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'paid' => 'decimal:2', 'balance' => 'decimal:2'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
