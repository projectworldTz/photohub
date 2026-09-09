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

    protected static function booted(): void
    {
        static::creating(function (Gallery $gallery) {
            $gallery->expires_at ??= ($gallery->created_at ?? now())->copy()->addDays(30);
        });
        static::updating(function (Gallery $gallery) {
            // Clearing the form must not remove the lifecycle protection.
            if ($gallery->expires_at === null) {
                $gallery->expires_at = $gallery->getOriginal('expires_at') ?? $gallery->created_at->copy()->addDays(30);
            }
            if ($gallery->isDirty('expires_at')) {
                $gallery->expired_at = $gallery->expires_at->isFuture() ? null : now();
                $gallery->expiry_notice_days = null;
            }
        });
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || ($this->expires_at && $this->expires_at->lessThanOrEqualTo(now()));
    }

    public function expiryLabel(): string
    {
        if ($this->isExpired()) {
            return 'This gallery has expired.';
        }
        $days = (int) now()->startOfDay()->diffInDays($this->expires_at->copy()->startOfDay(), false);

        return match (true) {
            $days <= 0 => 'Expires today',
            $days === 1 => 'Gallery expires tomorrow.',
            default => "Gallery expires in {$days} days.",
        };
    }

    protected function casts(): array
    {
        return ['expired_at' => 'datetime', 'event_date' => 'date', 'expires_at' => 'datetime', 'selection_completed_at' => 'datetime', 'downloads_enabled' => 'boolean', 'payment_required' => 'boolean', 'watermark_enabled' => 'boolean', 'require_exact_selection' => 'boolean', 'face_search_enabled' => 'boolean'];
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
