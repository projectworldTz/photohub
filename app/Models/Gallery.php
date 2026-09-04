<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Gallery extends Model
{
    use BelongsToBusiness,SoftDeletes;

    protected $fillable = ['business_id', 'gallery_number', 'name', 'code', 'customer_id', 'booking_id', 'shoot_id', 'event', 'event_date', 'description', 'type', 'privacy', 'pin_hash', 'expires_at', 'downloads_enabled', 'payment_required', 'watermark_enabled', 'selection_limit', 'require_exact_selection', 'extra_photo_price', 'photo_price', 'status', 'selection_completed_at', 'submitted_selection_count', 'face_search_enabled'];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return ['event_date' => 'date', 'expires_at' => 'datetime', 'selection_completed_at' => 'datetime', 'downloads_enabled' => 'boolean', 'payment_required' => 'boolean', 'watermark_enabled' => 'boolean', 'require_exact_selection' => 'boolean', 'face_search_enabled' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(GalleryAccessToken::class);
    }

    public function finalPhotos(): HasMany
    {
        return $this->hasMany(FinalPhoto::class);
    }
}
