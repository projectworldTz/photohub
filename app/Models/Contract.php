<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'booking_id', 'customer_id', 'content', 'status', 'accepted_at', 'accepted_name', 'accepted_ip'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }
}
