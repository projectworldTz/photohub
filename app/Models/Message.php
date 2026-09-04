<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'sender_id', 'customer_id', 'booking_id', 'gallery_id', 'body', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
