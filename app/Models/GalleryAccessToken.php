<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class GalleryAccessToken extends Model
{
    protected $fillable = ['gallery_id', 'purpose', 'token_hash', 'token_encrypted', 'generated_at', 'expires_at', 'revoked_at', 'last_accessed_at', 'notified_30_at', 'notified_7_at', 'notified_1_at'];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_accessed_at' => 'datetime', 'notified_30_at' => 'datetime', 'notified_7_at' => 'datetime', 'notified_1_at' => 'datetime'];
    }

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function plainToken(): string
    {
        return Crypt::decryptString($this->token_encrypted);
    }

    public function isUsable(): bool
    {
        return ! $this->revoked_at && $this->expires_at->isFuture();
    }
}
