<?php

namespace App\Services;

use App\Models\Gallery;

class GalleryExpiryService
{
    public function expire(): int
    {
        // A separate lifecycle marker preserves selection/editing/delivery status.
        return Gallery::where('expires_at', '<=', now())->whereNull('expired_at')->update(['expired_at' => now()]);
    }

    public function remind(): void
    {
        Gallery::with('business')->where('expires_at', '>', now())->where('expires_at', '<=', now()->addDays(8))->whereNotIn('status', ['archived', 'expired'])->chunkById(100, function ($galleries) {
            foreach ($galleries as $gallery) {
                if (! $gallery->business) {
                    continue;
                }
                $days = (int) now()->startOfDay()->diffInDays($gallery->expires_at->copy()->startOfDay(), false);
                $threshold = match (true) {
                    $days <= 1 => 1, $days <= 3 => 3, $days <= 7 => 7, default => null
                };
                if ($threshold && ($gallery->expiry_notice_days === null || $gallery->expiry_notice_days > $threshold)) {
                    app(NotificationService::class)->business($gallery->business, 'Gallery expires soon', $gallery->name.': '.$gallery->expiryLabel(), route('galleries.show', $gallery));
                    $gallery->forceFill(['expiry_notice_days' => $threshold])->saveQuietly();
                }
            }
        });
    }
}
