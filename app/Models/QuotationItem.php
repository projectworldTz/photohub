<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotationItem extends Model
{
    protected $fillable = ['quotation_id', 'description', 'quantity', 'unit_price', 'total'];
}
