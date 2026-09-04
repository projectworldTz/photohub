<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'date', 'category', 'amount', 'description', 'booking_id', 'receipt_path', 'recorded_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }
}
