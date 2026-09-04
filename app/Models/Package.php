<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Package extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = ['business_id', 'name', 'category', 'description', 'price', 'deposit_amount', 'duration_minutes', 'photographers_count', 'photos_count', 'edited_photos_count', 'album_included', 'video_included', 'drone_included', 'prints_included', 'delivery_days', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'deposit_amount' => 'decimal:2', 'album_included' => 'boolean', 'video_included' => 'boolean', 'drone_included' => 'boolean', 'prints_included' => 'boolean', 'is_active' => 'boolean'];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
