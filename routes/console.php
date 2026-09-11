<?php

use App\Models\Business;
use App\Models\Gallery;
use App\Models\GalleryAccessToken;
use App\Models\Invoice;
use App\Models\Photo;
use App\Models\Quotation;
use App\Models\Subscription;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\GalleryExpiryService;
use App\Services\GallerySyncService;
use App\Services\NotificationService;
use App\Services\PhotoStorage;
use App\Services\ReminderService;
use App\Services\StorageQuotaService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('photohub:storage-reconcile {--business=}', function () {
    $query = Business::withTrashed();
    if ($this->option('business')) {
        $query->whereKey($this->option('business'));
    }
    $query->eachById(function ($business) {
        $quota = app(StorageQuotaService::class);
        $quota->reconcile($business);
        $usage = $quota->usage($business->fresh());
        $this->info($business->id.': '.$business->name.' — '.$quota::format($usage['used']).' / '.$quota::format($usage['limit']));
    });
})->purpose('Inventory existing files without modifying them; safely initialize legacy quotas');

Artisan::command('photohub:galleries-expire', function () {
    $this->info(app(GalleryExpiryService::class)->expire().' galleries marked expired; files retained.');
})->purpose('Mark expired galleries without changing workflow status or deleting files');

Schedule::call(fn () => app(GalleryExpiryService::class)->remind())->name('photohub-gallery-expiry-reminders')->dailyAt('08:05')->withoutOverlapping();

Artisan::command('photohub:create-admin {email} {--name=PhotoHub Administrator}', function (string $email) {
    $password = $this->secret('Choose a password (at least 8 characters)');
    $confirmation = $this->secret('Confirm the password');
    $validation = Validator::make(
        ['email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
        ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:8', 'confirmed']],
    );

    if ($validation->fails()) {
        foreach ($validation->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    $user = User::query()->updateOrCreate(
        ['email' => strtolower($email)],
        ['name' => (string) $this->option('name'), 'password' => Hash::make($password), 'is_super_admin' => true, 'is_active' => true],
    );
    $this->info("Super administrator {$user->email} is ready.");

    return 0;
})->purpose('Create or reset the live platform super administrator');

Artisan::command('photohub:seed-demo {--force}', function () {
    if (app()->environment('production') && ! $this->option('force') && ! $this->confirm('Create public demo accounts and sample studio data on this live installation?')) {
        $this->warn('Demo data was not created.');

        return 1;
    }

    $originalEnvironment = app()->environment();
    app()->detectEnvironment(fn () => 'demo');
    $exitCode = $this->call('db:seed', ['--force' => true]);
    app()->detectEnvironment(fn () => $originalEnvironment);
    if ($exitCode === 0) {
        $this->warn('Demo login: owner@example.com / PhotoHub2026! — change this password before sharing the site.');
    }

    return $exitCode;
})->purpose('Seed an idempotent demonstration studio across all modules');

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
    app(GalleryExpiryService::class)->expire();
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
            if (! $token->isUsable() || ! $token->gallery->business || $token->expires_at->greaterThanOrEqualTo($token->gallery->expires_at)) {
                continue;
            }
            $days = (int) today()->diffInDays($token->expires_at->copy()->startOfDay(), false);
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

Artisan::command('photohub:studio-token {business} {--revoke}', function (int $business) {
    if (config('photohub.mode') !== 'cloud') {
        $this->error('Run token provisioning on the cloud installation.');

        return 1;
    }
    Business::findOrFail($business);
    if ($this->option('revoke')) {
        DB::table('studio_api_tokens')->where('business_id', $business)->update(['revoked_at' => now()]);
        $this->info('Previous studio credentials revoked.');

        return 0;
    }
    $plain = Str::random(64);
    DB::table('studio_api_tokens')->insert(['business_id' => $business, 'token_hash' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);
    $this->info('Store this credential securely in the matching local installation; it is shown only once:');
    $this->line($plain);
})->purpose('Issue or revoke a per-studio cloud API token');

Artisan::command('photohub:sync {--limit=10}', function () {
    $this->info('Automatic synchronization is retired. Use Online Sharing in the gallery.');
})->purpose('Compatibility command: directs users to explicit online sharing');

Artisan::command('photohub:sharing-prune {--days=90}', function () {
    $days = max(30, (int) $this->option('days'));
    $count = SyncJob::whereNotNull('explicit_requested_at')->whereIn('status', ['completed', 'cancelled'])
        ->where('completed_at', '<', now()->subDays($days))->delete();
    $this->info($count.' obsolete sharing operation records pruned; gallery data and legacy history retained.');
})->purpose('Prune completed explicit sharing records older than the retention period');
Schedule::command('photohub:sharing-prune')->daily()->withoutOverlapping();
