<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class BusinessUser extends Pivot
{
    protected $table = 'business_user';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $fillable = ['business_id', 'user_id', 'employee_number', 'job_title', 'joined_at', 'status'];

    protected function casts(): array
    {
        return ['joined_at' => 'date'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'business_user_role', 'business_user_id', 'role_id');
    }

    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class, 'booking_staff', 'business_user_id', 'booking_id')->withPivot('assignment_role');
    }

    public function shoots(): BelongsToMany
    {
        return $this->belongsToMany(Shoot::class, 'shoot_staff', 'business_user_id', 'shoot_id')->withPivot('assignment_role');
    }
}
