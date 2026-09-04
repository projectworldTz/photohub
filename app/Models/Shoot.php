<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Shoot extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'shoot_number', 'customer_id', 'booking_id', 'event', 'location', 'shoot_date', 'start_time', 'end_time', 'notes', 'status', 'expected_delivery_date'];

    protected function casts(): array
    {
        return ['shoot_date' => 'date', 'expected_delivery_date' => 'date'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(BusinessUser::class, 'shoot_staff', 'shoot_id', 'business_user_id', 'id', 'id')->withPivot('assignment_role');
    }
}
