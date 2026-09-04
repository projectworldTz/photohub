<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Gallery;
use App\Models\GalleryAccessToken;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class GalleryAccessService
{
    public function generate(Gallery $gallery, string $purpose, ?\DateTimeInterface $expiresAt = null, bool $revokePrevious = true): GalleryAccessToken
    {
        if ($revokePrevious) {
            $gallery->accessTokens()->where('purpose', $purpose)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
        $plain = Str::random(48);
        $record = $gallery->accessTokens()->create(['purpose' => $purpose, 'token_hash' => hash('sha256', $plain), 'token_encrypted' => Crypt::encryptString($plain), 'generated_at' => now(), 'expires_at' => $expiresAt ?? now()->addYear()]);
        $this->log($gallery, $purpose.'_link_generated', ['token_id' => $record->id, 'expires_at' => $record->expires_at]);

        return $record;
    }

    public function resolve(string $plain, string $purpose): ?GalleryAccessToken
    {
        return GalleryAccessToken::with(['gallery.business', 'gallery.customer'])->where('token_hash', hash('sha256', $plain))->where('purpose', $purpose)->first();
    }

    public function url(GalleryAccessToken $token): string
    {
        return route($token->purpose === 'selection' ? 'selection.show' : 'delivery.show', $token->plainToken());
    }

    public function touch(GalleryAccessToken $token): void
    {
        $token->forceFill(['last_accessed_at' => now()])->saveQuietly();
    }

    public function log(Gallery $gallery, string $action, array $properties = []): void
    {
        ActivityLog::create(['business_id' => $gallery->business_id, 'user_id' => auth()->id(), 'action' => $action, 'subject_type' => Gallery::class, 'subject_id' => $gallery->id, 'properties' => $properties, 'ip_address' => request()?->ip()]);
    }
}
