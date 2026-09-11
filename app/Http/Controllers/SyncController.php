<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\SyncJob;
use App\Services\CloudApiService;
use App\Services\OnlineGalleryService;
use App\Services\PhotoStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Legacy name and routes remain valid for bookmarks. */
class SyncController extends Controller
{
    private function businessId(): int
    {
        abort_unless(app()->bound('currentBusiness') && PhotoStorage::isLocal(), 403);
        $id = app('currentBusiness')->id;
        return $id;
    }

    public function index()
    {
        $id = $this->businessId();
        return view('sync.index', [
            'galleries' => Gallery::forBusiness($id)->withCount(['photos', 'finalPhotos',
                'photos as cloud_photos_count' => fn ($q) => $q->where('is_proof', true)->where('sync_status', 'synced')])->latest()->paginate(25),
            'connection' => Cache::get('photohub-connection-'.$id, 'Not checked'),
        ]);
    }

    public function connection(CloudApiService $api, OnlineGalleryService $sharing)
    {
        $id = $this->businessId();
        try {
            $result = $api->request($id, 'get', 'health');
            Cache::put('photohub-connection-'.$id, 'Connected (last check: '.now()->format('H:i').')', 300);
            Cache::put('photohub-cloud-storage-'.$id, $result['storage'] ?? null, 86400);
        } catch (Throwable $error) {
            return back()->withErrors(['sharing' => $sharing->safeError($error)]);
        }
        return back()->with('success', 'Cloud sharing connection checked.');
    }

    public function start(Request $request, Gallery $gallery, OnlineGalleryService $sharing)
    {
        abort_unless($gallery->business_id === $this->businessId(), 403);
        $type = match ($request->route('sharingAction')) {
            'previews' => 'publish', 'finals' => 'finals', 'selections' => 'selections', 'remove' => 'remove',
            default => abort(404),
        };
        return $this->begin($request, $gallery, $sharing, $type);
    }

    public function action(Request $request, Gallery $gallery, OnlineGalleryService $sharing)
    {
        abort_unless($gallery->business_id === $this->businessId(), 403);
        $data = $request->validate(['action' => 'required|in:publish,selections,finals,remove,retry']);
        if ($data['action'] === 'retry') {
            return back()->withErrors(['sharing' => 'Choose Share Previews Online, Get Client Selections, or Upload Finished Photos to resume that action.']);
        }
        return $this->begin($request, $gallery, $sharing, $data['action']);
    }

    private function begin(Request $request, Gallery $gallery, OnlineGalleryService $sharing, string $type)
    {
        if ($type === 'remove') {
            $request->validate(['confirm_remove' => 'required|accepted']);
        }
        $job = $sharing->enqueue($gallery, $type);
        return $this->advance($request, $gallery, $job, $sharing);
    }

    public function continue(Request $request, Gallery $gallery, SyncJob $operation, OnlineGalleryService $sharing)
    {
        abort_unless($gallery->business_id === $this->businessId() && $operation->business_id === $gallery->business_id
            && $operation->gallery_id === $gallery->id && $operation->explicit_requested_at, 403);
        if ($operation->status === 'failed') {
            return $this->result($request, $gallery, $operation, $sharing);
        }
        return $this->advance($request, $gallery, $operation, $sharing);
    }

    private function advance(Request $request, Gallery $gallery, SyncJob $job, OnlineGalleryService $sharing)
    {
        @set_time_limit(300);
        $sharing->process($job);
        return $this->result($request, $gallery, $job->fresh(), $sharing);
    }

    private function result(Request $request, Gallery $gallery, SyncJob $job, OnlineGalleryService $sharing)
    {
        $gallery->refresh();
        $photos = in_array($job->type, ['publish', 'finals'], true) ? $sharing->photos($gallery, $job->type) : null;
        $total = $photos ? (clone $photos)->count() : 0;
        $uploaded = $photos ? (clone $photos)->where('sync_status', 'synced')->count() : 0;
        $failed = $photos ? (clone $photos)->where('sync_status', 'failed')->count() : 0;
        $message = match ($job->status) {
            'completed' => match ($job->type) {
                'publish' => 'Preview gallery is online. Your selection link is ready.',
                'finals' => 'Finished photos are online. Your download link is ready.',
                'selections' => 'Client selections checked. Submitted selections have been imported.',
                'remove' => 'Online gallery removed. Your local files remain on this computer.',
            },
            'failed' => $job->last_error,
            'cancelled' => 'This sharing operation was cancelled.',
            default => $photos ? "$uploaded of $total photos uploaded; $failed failed. Keep this page open to continue." : 'Sharing action in progress.',
        };
        $result = ['status' => $job->status, 'message' => $message, 'uploaded' => $uploaded, 'total' => $total, 'failed' => $failed,
            'continue_url' => route('online.continue', [$gallery, $job]),
            'url' => $job->status === 'completed' ? match ($job->type) {'publish' => $gallery->cloud_url, 'finals' => $gallery->cloud_final_url, default => null} : null];
        if ($request->expectsJson()) {
            return response()->json($result);
        }
        if ($job->status === 'failed') {
            return back()->withErrors(['sharing' => $message]);
        }
        return back()->with('success', $message)->with('sharing_operation', in_array($job->status, ['queued', 'running'], true) ? $result : null);
    }
}
