<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class EquipmentMaintenance extends Model
{
    use BelongsToBusiness;

    protected $table = 'equipment_maintenance';

    protected $fillable = ['business_id', 'equipment_id', 'maintenance_date', 'problem', 'repair_company', 'cost', 'next_maintenance_date', 'notes'];

    protected function casts(): array
    {
        return ['maintenance_date' => 'date', 'next_maintenance_date' => 'date', 'cost' => 'decimal:2'];
    }
}
