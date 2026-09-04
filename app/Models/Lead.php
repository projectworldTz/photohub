<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'name', 'phone', 'email', 'event_type', 'event_date', 'start_time', 'end_time', 'estimated_budget', 'message', 'source', 'assigned_user_id', 'package_id', 'status', 'converted_customer_id', 'converted_at'];

    protected function casts(): array
    {
        return ['event_date' => 'date', 'estimated_budget' => 'decimal:2', 'converted_at' => 'datetime'];
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function convertedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
