<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = ['business_id', 'user_id', 'customer_number', 'first_name', 'last_name', 'phone', 'whatsapp', 'email', 'gender', 'address', 'city', 'country', 'date_of_birth', 'notes', 'status', 'face_search_enabled', 'customer_consent_at', 'face_reference', 'face_identifier'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'face_search_enabled' => 'boolean', 'customer_consent_at' => 'datetime'];
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function shoots(): HasMany
    {
        return $this->hasMany(Shoot::class);
    }

    public function galleries(): HasMany
    {
        return $this->hasMany(Gallery::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
