<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'title', 'assigned_user_id', 'booking_id', 'due_at', 'priority', 'status'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }
}
