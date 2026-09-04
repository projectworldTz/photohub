<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'customer_id', 'booking_id', 'rating', 'comment', 'is_public'];
}
