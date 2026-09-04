<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
{
    use BelongsToBusiness,SoftDeletes;

    protected $fillable = ['business_id', 'quotation_number', 'customer_id', 'booking_id', 'date', 'expiry_date', 'subtotal', 'discount', 'tax', 'total', 'notes', 'terms', 'status', 'invoice_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'expiry_date' => 'date', 'subtotal' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
