<?php

namespace App\Services;

use App\Exceptions\SelectionSyncConflict;
use App\Models\FinalPhoto;
use App\Models\Gallery;
use App\Models\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GallerySyncService
{
    public function __construct(private CloudApiService $api) {}

    public function enqueue(Gallery $gallery, string $type): SyncJob
    {
        abort_unless(PhotoStorage::isLocal() && $gallery->business_id === config('photohub.business_id') && ! $gallery->cloud_replica, 403);
        abort_unless(in_array($type, ['publish', 'selections', 'finals', 'remove'], true), 422);

        return DB::transaction(function () use ($gallery, $type) {
            $gallery = Gallery::lockForUpdate()->findOrFail($gallery->id);
            if (! $gallery->sync_uuid) {
                $gallery->forceFill(['sync_uuid' => (string) Str::uuid()])->save();
            }
            if ($type === 'remove') {
                SyncJob::where('gallery_id', $gallery->id)->whereIn('status', ['queued', 'failed'])->update(['status' => 'cancelled', 'completed_at' => now()]);
            }
            $existing = SyncJob::where('gallery_id', $gallery->id)->where('type', $type)->whereIn('status', ['queued', 'running', 'failed'])->first();
            if ($existing) {
                if ($existing->status !== 'running') {
                    $existing->update(['status' => 'queued', 'next_retry_at' => now()]);
                }

                return $existing;
            }
            $gallery->forceFill(['cloud_status' => 'queued', 'sync_error' => null])->save();

            return SyncJob::create(['business_id' => $gallery->business_id, 'gallery_id' => $gallery->id, 'type' => $type, 'queued_at' => now(), 'next_retry_at' => now()]);
        });
    }

    public function process(SyncJob $job): void
    {
        // Bounded batches fit inside the lease; a dead worker is recovered after 30 minutes.
        $lock = Cache::lock('photohub-sync-gallery-'.$job->gallery_id, 1800);
        if (! $lock->get()) {
            return;
        }
        try {
            $job->refresh();
            if (in_array($job->status, ['completed', 'cancelled'], true)) {
                return;
            }
            // Preserve ordering, particularly an explicit cloud removal after uploads.
            if (SyncJob::where('gallery_id', $job->gallery_id)->where('id', '<', $job->id)->whereNotIn('status', ['completed', 'cancelled'])->exists()) {
                return;
            }
            $job->update(['status' => 'running', 'attempts' => $job->attempts + 1, 'started_at' => now()]);
            $gallery = Gallery::where('business_id', $job->business_id)->findOrFail($job->gallery_id);
            $health = $this->api->request($job->business_id, 'get', 'health');
            Cache::put('photohub-connection-'.$job->business_id, 'Connected (last sync check)', 300);
            if (isset($health['storage'])) {
                Cache::put('photohub-cloud-storage-'.$job->business_id, $health['storage'], 86400);
            }
            $base = 'galleries/'.$gallery->sync_uuid;
            if ($job->type === 'remove') {
                $this->api->request($job->business_id, 'delete', $base);
                $gallery->forceFill(['cloud_status' => 'deleted_cloud', 'cloud_gallery_id' => null, 'cloud_url' => null, 'cloud_final_url' => null, 'sync_error' => null])->save();
                foreach ([$gallery->photos(), $gallery->finalPhotos()] as $query) {
                    $query->update(['sync_status' => 'deleted_cloud', 'cloud_photo_id' => null]);
                }
            } elseif ($job->type === 'selections') {
                app(SelectionSyncService::class)->apply($gallery, $this->api->request($job->business_id, 'get', $base.'/selections'));
                $gallery->forceFill(['cloud_status' => 'synced', 'last_synced_at' => now(), 'sync_error' => null])->save();
            } else {
                if ($gallery->isExpired()) {
                    throw new RuntimeException('Extend this gallery before publishing.');
                }
                $result = $this->api->request($job->business_id, 'put', $base, [
                    'name' => $gallery->name, 'event' => $gallery->event, 'event_date' => $gallery->event_date?->toDateString(), 'description' => $gallery->description, 'expires_at' => $gallery->expires_at->toIso8601String(),
                    'extra_photo_price' => $gallery->extra_photo_price, 'selection_limit' => $gallery->selection_limit, 'require_exact_selection' => (bool) $gallery->require_exact_selection,
                    'downloads_enabled' => (bool) $gallery->downloads_enabled, 'pin_hash' => $gallery->pin_hash,
                ]);
                if (empty($result['cloud_gallery_id'])) {
                    throw new RuntimeException('Invalid gallery response.');
                }
                $gallery->forceFill(['cloud_gallery_id' => $result['cloud_gallery_id'], 'cloud_status' => 'uploading'])->save();
                $final = $job->type === 'finals';
                $query = $final ? $gallery->finalPhotos() : $gallery->photos()->where('is_proof', true);
                // Older galleries stored delivered files in photos rather than final_photos.
                if ($final && ! $gallery->finalPhotos()->exists()) {
                    $query = $gallery->photos()->where('is_final', true);
                }
                $query->where('status', 'ready');
                $batch = (clone $query)->where('sync_status', '!=', 'synced')->orderBy('id')->limit(20)->get();
                foreach ($batch as $photo) {
                    $photo->forceFill(['sync_status' => 'uploading', 'sync_error' => null])->save();
                    try {
                        $path = $final ? $photo->original_path : $photo->preview_path;
                        if (! $path || (! $final && $path === $photo->original_path)) {
                            throw new RuntimeException('Optimized preview is missing.');
                        }
                        $result = $this->api->request($job->business_id, 'post', $base.'/photos', [
                            'uuid' => $photo->uuid, 'kind' => $final ? 'final' : 'preview', 'filename' => $photo->filename,
                            'proof_uuid' => $final && $photo instanceof FinalPhoto && $photo->proofPhoto?->cloud_photo_id ? $photo->proofPhoto->uuid : null,
                        ], $path);
                        if (empty($result['cloud_photo_id'])) {
                            throw new RuntimeException('Invalid photo response.');
                        }
                        $photo->forceFill(['cloud_photo_id' => $result['cloud_photo_id'], 'sync_status' => 'synced', 'synced_at' => now(), 'cloud_uploaded_at' => now()])->save();
                    } catch (Throwable $e) {
                        $photo->forceFill(['sync_status' => 'failed', 'sync_error' => 'Upload failed. Retry synchronization.'])->save();
                        throw $e;
                    }
                }
                if ((clone $query)->where('sync_status', '!=', 'synced')->exists()) {
                    $job->update(['status' => 'queued', 'next_retry_at' => now(), 'last_error' => null]);

                    return;
                }
                $result = $this->api->request($job->business_id, 'post', $base.'/publish', ['kind' => $final ? 'final' : 'preview', 'uuids' => (clone $query)->pluck('uuid')->all()]);
                $url = $result['public_url'] ?? '';
                if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_HOST) !== parse_url(config('photohub.cloud_url'), PHP_URL_HOST) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)) {
                    throw new RuntimeException('Invalid public gallery URL.');
                }
                $gallery->forceFill([$final ? 'cloud_final_url' : 'cloud_url' => $url, 'cloud_status' => 'synced', 'last_synced_at' => now(), 'sync_error' => null])->save();
            }
            $job->update(['status' => 'completed', 'completed_at' => now(), 'last_error' => null]);
        } catch (Throwable $error) {
            // Never expose HTTP bodies, credentials, or filesystem paths in the UI/log.
            $message = 'Cloud unavailable or synchronization rejected. Check connection, quota and configuration, then retry.';
            $job->update(['status' => 'failed', 'last_error' => $message, 'next_retry_at' => now()->addSeconds(min(3600, 30 * (2 ** min($job->attempts, 7))))]);
            if ($error instanceof SelectionSyncConflict) {
                $message = 'Selection conflict. Review local selections and reopen the local selection before retrying.';
                $job->update(['last_error' => $message]);
            }
            Cache::put('photohub-connection-'.$job->business_id, 'Cloud unavailable / sync rejected', 300);
            Gallery::whereKey($job->gallery_id)->where('business_id', $job->business_id)->update(['cloud_status' => $error instanceof SelectionSyncConflict ? 'conflict' : 'failed', 'sync_error' => $message]);
        } finally {
            $lock->release();
        }
    }
}
