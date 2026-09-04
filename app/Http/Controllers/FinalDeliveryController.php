<?php

namespace App\Http\Controllers;

use App\Models\FinalPhoto;
use App\Services\GalleryAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class FinalDeliveryController extends Controller
{
    public function show(string $token, GalleryAccessService $access): View
    {
        $record = $access->resolve($token, 'final_delivery');
        if (! $record || ! $record->isUsable()) {
            return view('public.link-unavailable', ['expired' => $record?->expires_at?->isPast()]);
        }
        abort_unless(in_array($record->gallery->status, ['final_published', 'delivered'], true), 404);
        if (! $record->last_accessed_at) {
            $access->log($record->gallery, 'final_link_opened');
        }
        $access->touch($record);
        $record->gallery->update(['status' => 'delivered']);

        return view('public.delivery', ['gallery' => $record->gallery, 'token' => $token, 'photos' => $record->gallery->finalPhotos()->where('status', 'ready')->paginate(40)]);
    }

    public function image(string $token, FinalPhoto $photo, GalleryAccessService $access): Response
    {
        $gallery = $this->gallery($token, $access);
        abort_unless($photo->gallery_id === $gallery->id, 404);
        $path = $photo->preview_path ?: $photo->thumbnail_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private,max-age=3600']);
    }

    public function download(string $token, FinalPhoto $photo, GalleryAccessService $access)
    {
        $gallery = $this->gallery($token, $access);
        abort_unless($gallery->downloads_enabled && $photo->gallery_id === $gallery->id && Storage::disk('local')->exists($photo->original_path), 403);
        $access->log($gallery, 'final_photo_downloaded', ['final_photo_id' => $photo->id]);

        return Storage::disk('local')->download($photo->original_path, $photo->filename);
    }

    public function zip(Request $request, string $token, GalleryAccessService $access): BinaryFileResponse
    {
        $gallery = $this->gallery($token, $access);
        abort_unless($gallery->downloads_enabled, 403);
        $ids = $request->input('all') ? $gallery->finalPhotos()->where('status', 'ready')->pluck('id')->all() : $request->validate(['photos' => 'required|array|min:1', 'photos.*' => 'integer'])['photos'];
        if ($ids === []) {
            throw ValidationException::withMessages(['photos' => 'No final photos are available to download.']);
        }
        $photos = $gallery->finalPhotos()->whereIn('id', array_unique($ids))->get();
        if ($photos->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['photos' => 'Invalid photo selection.']);
        }
        $dir = storage_path('app/private/temp-zips');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            abort(500, 'Unable to prepare the download archive.');
        }
        $path = $dir.'/'.uniqid('final-', true).'.zip';
        $zip = new ZipArchive;
        abort_unless($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500, 'Unable to create the download archive.');
        foreach ($photos as $index => $photo) {
            abort_unless(Storage::disk('local')->exists($photo->original_path), 404, "Final file missing for {$photo->filename}.");
            $zip->addFile(Storage::disk('local')->path($photo->original_path), sprintf('%04d-%s', $index + 1, basename($photo->filename)));
        }
        $zip->close();
        $access->log($gallery, $request->input('all') ? 'final_download_all' : 'final_download_selected', ['count' => $photos->count()]);

        return response()->download($path, str($gallery->name)->slug().'-'.$photos->count().'-Photos.zip')->deleteFileAfterSend(true);
    }

    private function gallery(string $plain, GalleryAccessService $access)
    {
        $record = $access->resolve($plain, 'final_delivery');
        abort_unless($record && $record->isUsable() && in_array($record->gallery->status, ['final_published', 'delivered'], true), 403);

        return $record->gallery;
    }
}
