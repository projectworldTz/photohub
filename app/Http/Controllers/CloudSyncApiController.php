<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\FinalPhoto;
use App\Models\Gallery;
use App\Models\Photo;
use App\Services\GalleryAccessService;
use App\Services\PhotoUploadService;
use App\Services\StorageQuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CloudSyncApiController extends Controller
{
    public function health(Request $request)
    {
        return response()->json(['connected' => true, 'cloud_studio_id' => $request->attributes->get('sync_business')->id, 'storage' => app(StorageQuotaService::class)->usage($request->attributes->get('sync_business'))]);
    }

    private function gallery(Request $request, string $uuid): Gallery
    {
        return Gallery::where('business_id', $request->attributes->get('sync_business')->id)->where('cloud_replica', true)->where('sync_uuid', $uuid)->firstOrFail();
    }

    public function upsert(Request $request, string $uuid)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255', 'expires_at' => 'required|date|after:now',
            'event' => 'nullable|string|max:255', 'event_date' => 'nullable|date', 'description' => 'nullable|string|max:10000',
            'extra_photo_price' => 'nullable|numeric|min:0|max:999999', 'selection_limit' => 'nullable|integer|min:1|max:10000', 'require_exact_selection' => 'required|boolean',
            'downloads_enabled' => 'required|boolean', 'pin_hash' => ['nullable', 'string', 'max:255', 'regex:/^\$2[aby]\$[0-9]{2}\$[.\/A-Za-z0-9]{53}$/'],
        ]);
        $business = $request->attributes->get('sync_business');
        $gallery = DB::transaction(function () use ($business, $uuid, $data) {
            // Serialize creates for this tenant and make lost-response retries idempotent.
            DB::table('businesses')->where('id', $business->id)->lockForUpdate()->first();
            $gallery = Gallery::where('sync_uuid', $uuid)->first();
            abort_if($gallery && ($gallery->business_id !== $business->id || ! $gallery->cloud_replica), 404);
            if (! $gallery) {
                abort_if(Gallery::withTrashed()->where('sync_uuid', $uuid)->exists(), 409);
                $customer = Customer::firstOrCreate(['business_id' => $business->id, 'customer_number' => 'SYNC-'.$uuid], ['first_name' => 'Gallery', 'last_name' => 'Customer', 'phone' => '']);
                $gallery = new Gallery(['business_id' => $business->id, 'customer_id' => $customer->id, 'gallery_number' => 'SYNC-'.$uuid, 'code' => Str::random(48), 'type' => 'proof', 'privacy' => 'private', 'status' => 'proofs_ready', 'watermark_enabled' => false]);
                $gallery->forceFill(['sync_uuid' => $uuid, 'cloud_replica' => true]);
            }
            $gallery->fill($data)->save();
            $gallery->accessTokens()->whereNull('revoked_at')->update(['expires_at' => $gallery->expires_at]);

            return $gallery;
        });

        return response()->json(['cloud_gallery_id' => $gallery->id]);
    }

    public function photo(Request $request, string $uuid)
    {
        $gallery = $this->gallery($request, $uuid);
        abort_if($gallery->isExpired(), 410);
        $data = $request->validate([
            'uuid' => 'required|uuid', 'kind' => 'required|in:preview,final', 'filename' => 'required|string|max:255',
            'proof_uuid' => 'nullable|uuid', 'file' => 'required|file|mimes:jpg,jpeg,png,webp|max:51200|dimensions:max_width=16000,max_height=16000',
        ]);
        if ($data['kind'] === 'preview') {
            $request->validate(['file' => 'max:2048|dimensions:max_width=2000,max_height=2000']);
        }

        return DB::transaction(function () use ($request, $gallery, $data) {
            DB::table('businesses')->where('id', $gallery->business_id)->lockForUpdate()->first();
            $model = $data['kind'] === 'final' ? FinalPhoto::class : Photo::class;
            $existing = $model::withTrashed()->where('uuid', $data['uuid'])->first();
            if ($existing) {
                abort_unless($existing->gallery_id === $gallery->id && $existing->business_id === $gallery->business_id, 404);
                abort_if($existing->trashed(), 409);

                return response()->json(['cloud_photo_id' => $existing->id]);
            }
            $proofId = null;
            if (! empty($data['proof_uuid'])) {
                $proofId = $gallery->photos()->where('uuid', $data['proof_uuid'])->firstOrFail()->id;
            }
            $photo = app(PhotoUploadService::class)->storeBatch($gallery, [$request->file('file')], $data['kind'] === 'final', $data['kind'] === 'final', $proofId)[0];
            $photo->forceFill(['uuid' => $data['uuid'], 'filename' => basename(str_replace('\\', '/', $data['filename'])), 'sync_status' => 'synced'])->save();

            return response()->json(['cloud_photo_id' => $photo->id]);
        });
    }

    public function publish(Request $request, string $uuid, GalleryAccessService $access)
    {
        $gallery = $this->gallery($request, $uuid);
        abort_if($gallery->isExpired(), 410);
        $data = $request->validate(['kind' => 'required|in:preview,final', 'uuids' => 'required|array|min:1|max:10000', 'uuids.*' => 'required|uuid|distinct']);

        return DB::transaction(function () use ($gallery, $data, $access) {
            DB::table('businesses')->where('id', $gallery->business_id)->lockForUpdate()->first();
            $relation = $data['kind'] === 'final' ? $gallery->finalPhotos() : $gallery->photos();
            abort_unless((clone $relation)->whereIn('uuid', $data['uuids'])->where('status', 'ready')->count() === count($data['uuids']), 409);
            // Retain replaced versions just as the existing final upload workflow does.
            if ($data['kind'] === 'final') {
                (clone $relation)->whereNotIn('uuid', $data['uuids'])->delete();
                $gallery->update(['status' => 'final_published']);
            }
            $purpose = $data['kind'] === 'final' ? 'final_delivery' : 'selection';
            $token = $gallery->accessTokens()->where('purpose', $purpose)->whereNull('revoked_at')->where('expires_at', '>', now())->latest('id')->first() ?? $access->generate($gallery, $purpose);

            return response()->json(['cloud_gallery_id' => $gallery->id, 'public_token' => $token->plainToken(), 'public_url' => $access->url($token)]);
        });
    }

    public function selections(Request $request, string $uuid)
    {
        $gallery = $this->gallery($request, $uuid);
        $uuids = $gallery->photos()->whereHas('selections', fn ($q) => $q->where('customer_id', $gallery->customer_id))->pluck('uuid');

        return response()->json(['gallery_uuid' => $uuid, 'submitted_at' => $gallery->selection_completed_at?->toIso8601String(), 'photo_uuids' => $uuids]);
    }

    public function remove(Request $request, string $uuid)
    {
        $business = $request->attributes->get('sync_business');
        $gallery = Gallery::where('business_id', $business->id)->where('cloud_replica', true)->where('sync_uuid', $uuid)->first();
        if (! $gallery) {
            return response()->json(['removed' => true]);
        }
        // Revoke first: an interrupted file cleanup must never leave the public link active.
        $gallery->accessTokens()->update(['revoked_at' => now()]);
        $gallery->update(['status' => 'archived']);
        foreach ([$gallery->photos()->withTrashed(), $gallery->finalPhotos()->withTrashed()] as $query) {
            $query->chunkById(100, function ($photos) use ($business) {
                foreach ($photos as $photo) {
                    foreach (array_unique(array_filter([$photo->original_path, $photo->preview_path, $photo->thumbnail_path])) as $path) {
                        if (DB::table('storage_files')->where('business_id', $business->id)->where('path_hash', hash('sha256', $path))->exists()) {
                            app(StorageQuotaService::class)->deleteFile($business, $path);
                        }
                    }
                    $photo->forceDelete();
                }
            });
        }
        $gallery->forceDelete();

        return response()->json(['removed' => true]);
    }
}
