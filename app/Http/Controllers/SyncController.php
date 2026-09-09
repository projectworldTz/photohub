<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Models\SyncJob;
use App\Services\CloudApiService;
use App\Services\GallerySyncService;
use App\Services\PhotoStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncController extends Controller
{
    private function businessId(): int
    {
        abort_unless(app()->bound('currentBusiness') && PhotoStorage::isLocal(), 403);
        $id = app('currentBusiness')->id;
        abort_unless($id === config('photohub.business_id'), 403);

        return $id;
    }

    public function index()
    {
        $id = $this->businessId();

        return view('sync.index', [
            'galleries' => Gallery::forBusiness($id)->withCount(['photos', 'finalPhotos', 'photos as cloud_photos_count' => fn ($q) => $q->where('sync_status', 'synced')])->latest()->paginate(25),
            'pending' => SyncJob::where('business_id', $id)->whereIn('status', ['queued', 'running'])->count(),
            'failed' => SyncJob::where('business_id', $id)->where('status', 'failed')->count(),
            'synced' => Gallery::forBusiness($id)->where('cloud_status', 'synced')->count(),
            'connection' => Cache::get('photohub-connection-'.$id, 'Not checked'),
        ]);
    }

    public function connection(CloudApiService $api)
    {
        $id = $this->businessId();
        try {
            $result = $api->request($id, 'get', 'health');
            Cache::put('photohub-connection-'.$id, 'Connected (last check: '.now()->format('H:i').')', 300);
            Cache::put('photohub-cloud-storage-'.$id, $result['storage'] ?? null, 86400);
        } catch (Throwable $e) {
            Cache::put('photohub-connection-'.$id, 'Offline / cloud unavailable', 300);
        }

        return back();
    }

    public function action(Request $request, Gallery $gallery, GallerySyncService $sync)
    {
        abort_unless($gallery->business_id === $this->businessId(), 403);
        $data = $request->validate(['action' => 'required|in:publish,selections,finals,remove,retry', 'confirm_remove' => 'exclude_unless:action,remove|required|accepted']);
        if ($data['action'] === 'retry') {
            SyncJob::where('business_id', $gallery->business_id)->where('gallery_id', $gallery->id)->where('status', 'failed')->update(['status' => 'queued', 'next_retry_at' => now()]);
        } else {
            $sync->enqueue($gallery, $data['action']);
        }

        return back()->with('success', 'Sync queued. The background processor will retry when the cloud is available.');
    }
}
