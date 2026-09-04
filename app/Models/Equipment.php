<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class Equipment extends Model
{
    use BelongsToBusiness;

    protected $table = 'equipment';

    protected $fillable = ['business_id', 'equipment_code', 'name', 'type', 'brand', 'model', 'serial_number', 'purchase_date', 'cost', 'condition', 'status'];

    protected function casts(): array
    {
        return ['purchase_date' => 'date', 'cost' => 'decimal:2'];
    }
}
