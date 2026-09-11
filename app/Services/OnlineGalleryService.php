<?php

namespace App\Services;

use App\Exceptions\SelectionSyncConflict;
use App\Models\FinalPhoto;
use App\Models\Gallery;
use App\Models\SyncJob;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OnlineGalleryService
{
    public function __construct(private CloudApiService $api) {}

    public function enqueue(Gallery $gallery, string $type): SyncJob
    {
        $this->authorize($gallery);
        abort_unless(in_array($type, ['publish', 'finals', 'selections', 'remove'], true), 422);

        // Retention does not depend on a continuously running local scheduler.
        SyncJob::where('business_id', $gallery->business_id)->whereNotNull('explicit_requested_at')
            ->whereIn('status', ['completed', 'cancelled'])->where('completed_at', '<', now()->subDays(90))->delete();

        return DB::transaction(function () use ($gallery, $type) {
            $gallery = Gallery::lockForUpdate()->findOrFail($gallery->id);
            if (! $gallery->sync_uuid) {
                $gallery->forceFill(['sync_uuid' => (string) Str::uuid()])->save();
            }
            $job = SyncJob::where('gallery_id', $gallery->id)->where('type', $type)->whereNotNull('explicit_requested_at')
                ->whereIn('status', ['queued', 'running', 'failed'])->latest('id')->first();
            if ($job && $job->status === 'running' && $job->started_at?->gt(now()->subMinutes(3))) {
                return $job;
            }
            if ($type === 'remove') {
                SyncJob::where('gallery_id', $gallery->id)->whereNotNull('explicit_requested_at')->whereIn('status', ['queued', 'failed'])
                    ->update(['status' => 'cancelled', 'completed_at' => now()]);
                $job = null;
            }
            $attributes = ['status' => 'queued', 'explicit_requested_at' => now(), 'last_photo_id' => 0,
                'last_error' => null, 'next_retry_at' => now(), 'completed_at' => null];
            if ($job) {
                $job->update($attributes);
            } else {
                $job = SyncJob::create($attributes + ['business_id' => $gallery->business_id,
                    'gallery_id' => $gallery->id, 'type' => $type, 'queued_at' => now()]);
            }
            return $job;
        });
    }

    private function authorize(Gallery $gallery): void
    {
        abort_unless(PhotoStorage::isLocal() && ! $gallery->cloud_replica, 403);
    }

    /** One file per HTTP request: the browser continues only this explicit operation. */
    public function process(SyncJob $job): void
    {
        $gallery = Gallery::where('business_id', $job->business_id)->findOrFail($job->gallery_id);
        $this->authorize($gallery);
        if (! $job->explicit_requested_at) {
            return; // Old queued work is historical, not permission to upload now.
        }
        $lock = Cache::lock('photohub-sync-gallery-'.$gallery->id, 180);
        if (! $lock->get()) {
            return;
        }
        $kind = match ($job->type) { 'publish' => 'preview', 'finals' => 'final', default => null };
        try {
            $job->refresh();
            if (in_array($job->status, ['completed', 'cancelled'], true)) {
                return;
            }
            $job->update(['status' => 'running', 'attempts' => $job->attempts + 1, 'started_at' => now()]);
            if ($kind) {
                $gallery->forceFill([$kind.'_share_status' => 'uploading', $kind.'_share_error' => null])->save();
            }
            $health = $this->api->request($gallery->business_id, 'get', 'health');
            Cache::put('photohub-connection-'.$gallery->business_id, 'Connected (last sharing check)', 300);
            if (isset($health['storage'])) {
                Cache::put('photohub-cloud-storage-'.$gallery->business_id, $health['storage'], 86400);
            }
            $base = 'galleries/'.$gallery->sync_uuid;
            if ($job->type === 'remove') {
                // This lock excludes any in-flight upload. Cancel paused explicit
                // operations so an old browser tab cannot recreate a removed gallery.
                SyncJob::where('gallery_id', $gallery->id)->where('id', '!=', $job->id)
                    ->whereNotNull('explicit_requested_at')->whereIn('status', ['queued', 'running', 'failed'])
                    ->update(['status' => 'cancelled', 'completed_at' => now()]);
                $this->api->request($gallery->business_id, 'delete', $base);
                $gallery->forceFill(['cloud_gallery_id' => null, 'cloud_url' => null, 'cloud_final_url' => null,
                    'preview_share_status' => 'removed_online', 'final_share_status' => 'removed_online',
                    'preview_share_error' => null, 'final_share_error' => null, 'selection_share_error' => null])->save();
                foreach ([$gallery->photos(), $gallery->finalPhotos()] as $query) {
                    $query->update(['sync_status' => 'local_only', 'cloud_photo_id' => null, 'sync_error' => null]);
                }
            } elseif ($job->type === 'selections') {
                if (! $gallery->cloud_gallery_id || ! $gallery->cloud_url) {
                    throw new RuntimeException('Share previews before retrieving selections.');
                }
                app(SelectionSyncService::class)->apply($gallery, $this->api->request($gallery->business_id, 'get', $base.'/selections'));
                $gallery->forceFill(['selection_synced_at' => now(), 'selection_share_error' => null])->save();
            } else {
                if ($gallery->isExpired()) {
                    throw new RuntimeException('Extend the gallery expiry before sharing online.');
                }
                $query = $this->photos($gallery, $job->type);
                if (! (clone $query)->exists()) {
                    throw new RuntimeException('Add ready photos before sharing this collection.');
                }
                $result = $this->api->request($gallery->business_id, 'put', $base, [
                    'name' => $gallery->name, 'event' => $gallery->event, 'event_date' => $gallery->event_date?->toDateString(),
                    'description' => $gallery->description, 'expires_at' => $gallery->expires_at->toIso8601String(),
                    'extra_photo_price' => $gallery->extra_photo_price, 'selection_limit' => $gallery->selection_limit,
                    'require_exact_selection' => (bool) $gallery->require_exact_selection,
                    'downloads_enabled' => $kind === 'final' || (bool) $gallery->downloads_enabled, 'pin_hash' => $gallery->pin_hash,
                ]);
                if (empty($result['cloud_gallery_id'])) {
                    throw new RuntimeException('Invalid gallery response.');
                }
                $gallery->forceFill(['cloud_gallery_id' => $result['cloud_gallery_id']])->save();
                $photo = (clone $query)->where('sync_status', '!=', 'synced')->where('id', '>', $job->last_photo_id)->orderBy('id')->first();
                if ($photo) {
                    $photo->forceFill(['sync_status' => 'uploading', 'sync_error' => null])->save();
                    try {
                        $path = $kind === 'final' ? $photo->original_path : $photo->preview_path;
                        if (! $path || ($kind === 'preview' && $path === $photo->original_path)) {
                            throw new RuntimeException('Optimized preview is missing.');
                        }
                        $result = $this->api->request($gallery->business_id, 'post', $base.'/photos', [
                            'uuid' => $photo->uuid, 'kind' => $kind === 'final' ? 'final' : 'preview', 'filename' => $photo->filename,
                            'proof_uuid' => $photo instanceof FinalPhoto && $photo->proofPhoto?->cloud_photo_id ? $photo->proofPhoto->uuid : null,
                        ], $path);
                        if (empty($result['cloud_photo_id'])) {
                            throw new RuntimeException('Invalid photo response.');
                        }
                        $photo->forceFill(['cloud_photo_id' => $result['cloud_photo_id'], 'sync_status' => 'synced',
                            'synced_at' => now(), 'cloud_uploaded_at' => now(), 'sync_error' => null])->save();
                    } catch (Throwable $error) {
                        $photo->forceFill(['sync_status' => 'failed', 'sync_error' => $this->safeError($error)])->save();
                        $job->update(['last_error' => $this->safeError($error)]);
                    }
                    $job->update(['last_photo_id' => $photo->id]);
                }
                if ((clone $query)->where('sync_status', '!=', 'synced')->where('id', '>', $job->last_photo_id)->exists()) {
                    $job->update(['status' => 'queued']);
                    return;
                }
                if ((clone $query)->where('sync_status', '!=', 'synced')->exists()) {
                    $this->fail($job, $gallery, $kind, $job->last_error ?: 'Some photos need uploading. Choose upload again to resume.');
                    return;
                }
                $result = $this->api->request($gallery->business_id, 'post', $base.'/publish', [
                    'kind' => $kind === 'final' ? 'final' : 'preview', 'uuids' => (clone $query)->pluck('uuid')->all(),
                ]);
                $url = $result['public_url'] ?? '';
                $expected = parse_url(app(CloudStudioService::class)->connection($gallery->business)->base_url);
                $actual = parse_url($url);
                if (! filter_var($url, FILTER_VALIDATE_URL) || ! $actual || isset($actual['user']) || isset($actual['pass'])
                    || ($actual['scheme'] ?? '') !== ($expected['scheme'] ?? '')
                    || ($actual['host'] ?? '') !== ($expected['host'] ?? '')
                    || ($actual['port'] ?? null) !== ($expected['port'] ?? null)) {
                    throw new RuntimeException('Invalid public gallery URL.');
                }
                $gallery->forceFill([$kind === 'final' ? 'cloud_final_url' : 'cloud_url' => $url,
                    $kind.'_share_status' => 'online', $kind.'_share_error' => null, $kind.'_shared_at' => now()]
                    + ($kind === 'final' ? ['downloads_enabled' => true] : []))->save();
            }
            $job->update(['status' => 'completed', 'completed_at' => now(), 'last_error' => null]);
        } catch (Throwable $error) {
            $this->fail($job, $gallery, $kind, $this->safeError($error));
        } finally {
            $lock->release();
        }
    }

    public function photos(Gallery $gallery, string $type)
    {
        $query = $type === 'finals' ? $gallery->finalPhotos() : $gallery->photos()->where('is_proof', true);
        if ($type === 'finals' && ! $gallery->finalPhotos()->exists()) {
            $query = $gallery->photos()->where('is_final', true);
        }
        return $query->where('business_id', $gallery->business_id)->where('status', 'ready');
    }

    private function fail(SyncJob $job, Gallery $gallery, ?string $kind, string $message): void
    {
        $job->update(['status' => 'failed', 'last_error' => $message, 'next_retry_at' => null]);
        if ($kind) {
            $gallery->forceFill([$kind.'_share_status' => 'failed', $kind.'_share_error' => $message])->save();
        } elseif ($job->type === 'selections') {
            $gallery->forceFill(['selection_share_error' => $message])->save();
        }
    }

    public function safeError(Throwable $error): string
    {
        if ($error instanceof \App\Exceptions\CloudConnectionException) return $error->getMessage();
        if ($error instanceof SelectionSyncConflict) {
            return 'Online selections differ from your saved local selection. Review and reopen the local selection before importing changes.';
        }
        if ($error instanceof ConnectionException) {
            return 'The cloud connection failed or timed out. Your local work is safe. Choose this sharing action again to resume.';
        }
        if ($error instanceof RequestException) {
            return match ($error->response->status()) {
                401 => 'The cloud rejected the studio credentials (401). Check the configured studio token.',
                403 => 'The cloud refused this upload or sharing request (403). Check cloud permissions and hosting security logs.',
                413 => 'The cloud rejected the upload size (413). Check hosting upload limits.',
                422 => 'The cloud rejected the photo or gallery details (422). Check file format, size, expiry and cloud quota.',
                429 => 'The cloud received too many requests (429). Try this sharing action again later.',
                default => 'The cloud returned an error ('.$error->response->status().'). Your local work is safe.',
            };
        }
        // Only known application messages are safe; never expose response bodies or paths.
        $allowed = ['Share previews before retrieving selections.', 'Extend the gallery expiry before sharing online.',
            'Add ready photos before sharing this collection.', 'Optimized preview is missing.',
            'Online sharing is not configured for this studio.', 'Online sharing requires HTTPS.'];
        return in_array($error->getMessage(), $allowed, true) ? $error->getMessage()
            : 'This online sharing action could not finish. Check the photo files and cloud configuration. Your local work is safe.';
    }
}
