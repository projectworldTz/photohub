<?php

use App\Models\Gallery;
use App\Models\GalleryAccessToken;
use App\Models\Invoice;
use App\Models\Photo;
use App\Models\Quotation;
use App\Models\Subscription;
use App\Services\NotificationService;
use App\Services\ReminderService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('photohub:recover-gallery-files {gallery}', function (int $gallery) {
    $record = Gallery::findOrFail($gallery);
    $disk = Storage::disk('local');
    $directory = "businesses/{$record->business_id}/galleries/{$record->id}/originals";
    $recovered = 0;
    foreach ($disk->files($directory) as $path) {
        $uuid = pathinfo($path, PATHINFO_FILENAME);
        if (Photo::where('uuid', $uuid)->exists()) {
            continue;
        }
        $fullPath = $disk->path($path);
        [$width, $height] = getimagesize($fullPath) ?: [null, null];
        $base = "businesses/{$record->business_id}/galleries/{$record->id}";
        Photo::create(['business_id' => $record->business_id, 'gallery_id' => $record->id, 'uuid' => $uuid, 'filename' => 'Recovered-'.$uuid.'.'.pathinfo($path, PATHINFO_EXTENSION), 'original_path' => $path, 'thumbnail_path' => $base.'/thumbnail/'.$uuid.'.jpg', 'preview_path' => $base.'/preview/'.$uuid.'.jpg', 'file_size' => filesize($fullPath), 'width' => $width, 'height' => $height, 'mime_type' => mime_content_type($fullPath), 'status' => 'ready', 'is_proof' => true, 'is_final' => false, 'is_downloadable' => false, 'watermarked' => true]);
        $recovered++;
    }
    $this->info("Recovered {$recovered} photo records.");
})->purpose('Recover gallery photo records from existing private originals');

Schedule::call(function () {
    Gallery::whereNotNull('expires_at')->where('expires_at', '<', now())->where('status', '!=', 'archived')->update(['status' => 'archived']);
    Invoice::where('due_date', '<', today())->whereIn('status', ['unpaid', 'partially_paid'])->update(['status' => 'overdue']);
    Quotation::where('expiry_date', '<', today())->whereIn('status', ['draft', 'sent'])->update(['status' => 'expired']);
    Subscription::where('ends_at', '<', now())->where('status', 'active')->update(['status' => 'expired']);
})->name('photohub-status-maintenance')->hourly()->withoutOverlapping();

Schedule::call(function () {
    $directory = storage_path('app/private/temp-zips');
    if (! is_dir($directory)) {
        return;
    }
    foreach (glob($directory.'/*.zip') ?: [] as $file) {
        if (filemtime($file) < now()->subHour()->timestamp) {
            unlink($file);
        }
    }
})->name('photohub-temp-zip-cleanup')->hourly()->withoutOverlapping();

Schedule::call(fn () => app(ReminderService::class)->send())->name('photohub-daily-reminders')->dailyAt('07:00')->withoutOverlapping();

Schedule::call(function () {
    GalleryAccessToken::with('gallery.business')->whereNull('revoked_at')->where('expires_at', '>', now())->chunkById(100, function ($tokens) {
        foreach ($tokens as $token) {
            $days = today()->diffInDays($token->expires_at, false);
            foreach ([30, 7, 1] as $threshold) {
                $field = 'notified_'.$threshold.'_at';
                if ($days === $threshold && ! $token->{$field}) {
                    app(NotificationService::class)->business($token->gallery->business, 'Gallery link expires soon', $token->gallery->name.' '.str_replace('_', ' ', $token->purpose).' link expires in '.$threshold.' '.str('day')->plural($threshold).'.', route('galleries.workflow', $token->gallery));
                    $token->update([$field => now()]);
                }
            }
        }
    });
})->name('photohub-gallery-link-expiry-reminders')->dailyAt('08:00')->withoutOverlapping();
