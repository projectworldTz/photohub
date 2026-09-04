<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentAssignment extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['business_id', 'equipment_id', 'shoot_id', 'business_user_id', 'assigned_at', 'returned_at', 'notes'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'returned_at' => 'datetime'];
    }

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(BusinessUser::class, 'business_user_id');
    }
}
