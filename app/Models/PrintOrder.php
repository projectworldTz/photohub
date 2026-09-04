<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class PrintOrder extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'order_id', 'customer_id', 'product', 'size', 'quantity', 'total', 'status'];
}
