<?php

namespace App\Http\Controllers;

use App\Models\FinalPhoto;
use App\Models\Gallery;
use App\Models\GalleryAccessToken;
use App\Models\Photo;
use App\Services\FinalPhotoService;
use App\Services\GalleryAccessService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use ZipArchive;

class GalleryWorkflowController extends Controller
{
    public function links(): View
    {
        $businessId = app('currentBusiness')->id;

        return view('galleries.links', ['tokens' => GalleryAccessToken::with('gallery.customer')->whereHas('gallery', fn ($query) => $query->where('business_id', $businessId))->latest('generated_at')->paginate(40)]);
    }

    public function selected(Gallery $gallery, GalleryAccessService $access): View
    {
        $this->guard($gallery);
        $selectedIds = DB::table('photo_selections')->join('photos', 'photos.id', '=', 'photo_selections.photo_id')->where('photos.gallery_id', $gallery->id)->pluck('photos.id');
        $selectionToken = $gallery->accessTokens()->where('purpose', 'selection')->whereNull('revoked_at')->latest()->first();
        $finalToken = $gallery->accessTokens()->where('purpose', 'final_delivery')->whereNull('revoked_at')->latest()->first();

        return view('galleries.workflow', ['gallery' => $gallery->load('customer'), 'selected' => $gallery->photos()->whereIn('id', $selectedIds)->get(), 'finalPhotos' => $gallery->finalPhotos()->with('proofPhoto')->get(), 'selectionToken' => $selectionToken, 'finalToken' => $finalToken, 'access' => $access]);
    }

    public function generate(Request $request, Gallery $gallery, GalleryAccessService $access): RedirectResponse
    {
        $this->guard($gallery);
        $data = $request->validate(['purpose' => 'required|in:selection,final_delivery', 'expires_at' => 'nullable|date|after:today', 'revoke_previous' => 'nullable|boolean']);
        if ($data['purpose'] === 'final_delivery') {
            abort_unless($gallery->finalPhotos()->where('status', 'ready')->exists(), 422, 'Upload final edited photos first.');
        }
        $access->generate($gallery, $data['purpose'], isset($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : null, $request->boolean('revoke_previous', true));
        if ($data['purpose'] === 'selection' && in_array($gallery->status, ['draft', 'proofs_ready'], true)) {
            $gallery->update(['status' => 'selection_link_ready']);
        }

        return back()->with('success', ucfirst(str_replace('_', ' ', $data['purpose'])).' link generated.');
    }

    public function token(Request $request, GalleryAccessToken $token, GalleryAccessService $access): RedirectResponse
    {
        $this->guard($token->gallery);
        $data = $request->validate(['action' => 'required|in:revoke,extend', 'expires_at' => 'nullable|required_if:action,extend|date|after:today']);
        $token->update($data['action'] === 'revoke' ? ['revoked_at' => now()] : ['expires_at' => Carbon::parse($data['expires_at'])->endOfDay()]);
        $access->log($token->gallery, 'gallery_link_'.$data['action'], ['token_id' => $token->id]);

        return back()->with('success', 'Gallery link updated.');
    }

    public function reopen(Gallery $gallery, GalleryAccessService $access): RedirectResponse
    {
        $this->guard($gallery);
        $gallery->update(['status' => 'selection_reopened', 'selection_completed_at' => null]);
        $access->log($gallery, 'selection_reopened');

        return back()->with('success', 'Selection reopened. The customer can modify it using the same link.');
    }

    public function editing(Gallery $gallery, GalleryAccessService $access): RedirectResponse
    {
        $this->guard($gallery);
        abort_unless($gallery->selection_completed_at, 422);
        $gallery->update(['status' => 'editing']);
        $access->log($gallery, 'editing_started');

        return back()->with('success', 'Editing marked as in progress.');
    }

    public function uploadFinals(Request $request, Gallery $gallery, FinalPhotoService $service, GalleryAccessService $access): RedirectResponse
    {
        $this->guard($gallery);
        $data = $request->validate(['finals' => 'required|array|min:1|max:100', 'finals.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:51200', 'proof_photo_id' => 'nullable|integer']);
        if (isset($data['proof_photo_id']) && ! $gallery->photos()->whereKey($data['proof_photo_id'])->whereHas('selections')->exists()) {
            return back()->withErrors(['proof_photo_id' => 'Choose a selected proof photo from this gallery.'])->withInput();
        }
        $gallery->update(['status' => 'final_upload_in_progress']);
        foreach ($data['finals'] as $file) {
            $final = $service->store($gallery, $file, $data['proof_photo_id'] ?? null);
            $access->log($gallery, 'final_photo_uploaded', ['final_photo_id' => $final->id, 'proof_photo_id' => $final->proof_photo_id]);
        }
        $selectedCount = DB::table('photo_selections')->join('photos', 'photos.id', '=', 'photo_selections.photo_id')->where('photos.gallery_id', $gallery->id)->count();
        if ($gallery->finalPhotos()->whereNotNull('proof_photo_id')->count() >= $selectedCount) {
            $gallery->update(['status' => 'final_ready']);
        }

        return back()->with('success', 'Final edited photos uploaded and matched where filenames agreed.');
    }

    public function publish(Request $request, Gallery $gallery, GalleryAccessService $access): RedirectResponse
    {
        $this->guard($gallery);
        $selected = DB::table('photo_selections')->join('photos', 'photos.id', '=', 'photo_selections.photo_id')->where('photos.gallery_id', $gallery->id)->count();
        $matched = $gallery->finalPhotos()->whereNotNull('proof_photo_id')->count();
        if ($matched < $selected && ! $request->boolean('confirm_incomplete')) {
            return back()->withErrors(['finals' => "{$selected} selected, {$matched} final photos matched. Confirm incomplete publishing to continue."]);
        }
        $gallery->update(['status' => 'final_published', 'downloads_enabled' => true]);
        $access->generate($gallery, 'final_delivery');
        $access->log($gallery, 'final_gallery_published', ['final_count' => $gallery->finalPhotos()->count()]);

        return back()->with('success', 'Final gallery published and its private delivery link generated.');
    }

    public function selectedZip(Gallery $gallery, GalleryAccessService $access)
    {
        $this->guard($gallery);
        $photos = $gallery->photos()->whereHas('selections')->get();
        abort_if($photos->isEmpty(), 422);
        $dir = storage_path('app/private/temp-zips');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            abort(500, 'Unable to prepare the download archive.');
        }
        $path = $dir.'/'.uniqid('selected-', true).'.zip';
        $zip = new ZipArchive;
        abort_unless($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500, 'Unable to create the download archive.');
        foreach ($photos as $index => $photo) {
            abort_unless(Storage::disk('local')->exists($photo->original_path), 404, "Original file missing for {$photo->filename}.");
            $zip->addFile(Storage::disk('local')->path($photo->original_path), sprintf('%04d-%s', $index + 1, basename($photo->filename)));
        }
        $zip->close();
        $access->log($gallery, 'selected_originals_downloaded', ['count' => $photos->count()]);

        return response()->download($path, str($gallery->name)->slug().'-Selected-Photos.zip')->deleteFileAfterSend(true);
    }

    public function selectedOriginal(Gallery $gallery, Photo $photo, GalleryAccessService $access)
    {
        $this->guard($gallery);
        abort_unless($photo->gallery_id === $gallery->id && $photo->selections()->exists(), 404);
        abort_unless(Storage::disk('local')->exists($photo->original_path), 404);
        $access->log($gallery, 'selected_original_downloaded', ['photo_id' => $photo->id]);

        return Storage::disk('local')->download($photo->original_path, $photo->filename);
    }

    public function finalPreview(Gallery $gallery, FinalPhoto $photo)
    {
        $this->guard($gallery);
        abort_unless($photo->gallery_id === $gallery->id && Storage::disk('local')->exists($photo->thumbnail_path), 404);

        return response(Storage::disk('local')->get($photo->thumbnail_path), 200, ['Content-Type' => 'image/jpeg']);
    }

    private function guard(Gallery $gallery): void
    {
        abort_unless($gallery->business_id === app('currentBusiness')->id && auth()->user()->hasPermission('galleries.manage'), 403);
    }
}
