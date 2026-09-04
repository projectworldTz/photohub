<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use BelongsToBusiness,SoftDeletes;

    protected $fillable = ['business_id', 'booking_number', 'customer_id', 'package_id', 'event_type', 'event_date', 'start_time', 'end_time', 'location', 'guests', 'notes', 'total_cost', 'deposit', 'balance', 'payment_status', 'status', 'expected_delivery_date'];

    protected function casts(): array
    {
        return ['event_date' => 'date', 'expected_delivery_date' => 'date', 'total_cost' => 'decimal:2', 'deposit' => 'decimal:2', 'balance' => 'decimal:2'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(BusinessUser::class, 'booking_staff', 'booking_id', 'business_user_id', 'id', 'id')->withPivot('assignment_role');
    }

    public function shoot(): HasOne
    {
        return $this->hasOne(Shoot::class);
    }
}
