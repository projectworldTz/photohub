<?php

namespace App\Http\Controllers;

use App\Models\GalleryAccessToken;
use App\Models\Photo;
use App\Services\GalleryAccessService;
use App\Services\PhotoSelectionService;
use App\Services\PhotoStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class GuestSelectionController extends Controller
{
    public function show(Request $request, string $token, GalleryAccessService $access): View
    {
        $record = $access->resolve($token, 'selection');
        if (! $record || ! $record->isUsable()) {
            return view('public.link-unavailable', ['expired' => ($record?->expires_at?->isPast() || $record?->gallery?->isExpired())]);
        }
        if ($record->gallery->pin_hash && ! $request->session()->get('gallery_token_pin.'.$record->id)) {
            return view('public.selection-pin', ['token' => $token, 'gallery' => $record->gallery]);
        }
        if (! $record->last_accessed_at) {
            $access->log($record->gallery, 'selection_link_opened');
        }
        $access->touch($record);
        $gallery = $record->gallery;
        if (in_array($gallery->status, ['proofs_ready', 'selection_link_ready', 'selection_sent'], true)) {
            $gallery->update(['status' => 'customer_selecting']);
        }
        $selected = DB::table('photo_selections')->join('photos', 'photos.id', '=', 'photo_selections.photo_id')->where('photos.gallery_id', $gallery->id)->where('customer_id', $gallery->customer_id)->pluck('photo_id');

        return view('public.selection', ['gallery' => $gallery, 'token' => $token, 'photos' => $gallery->photos()->where('is_proof', true)->where('status', 'ready')->paginate(40), 'selectedPhotoIds' => $selected]);
    }

    public function unlock(Request $request, string $token, GalleryAccessService $access): RedirectResponse
    {
        $record = $access->resolve($token, 'selection');
        abort_unless($record && $record->isUsable(), 404);
        $request->validate(['pin' => 'required|string']);
        if (! Hash::check($request->input('pin'), $record->gallery->pin_hash)) {
            return back()->withErrors(['pin' => 'The PIN is incorrect.']);
        }
        $request->session()->put('gallery_token_pin.'.$record->id, true);

        return redirect()->route('selection.show', $token);
    }

    public function toggle(Request $request, string $token, Photo $photo, GalleryAccessService $access, PhotoSelectionService $selections): JsonResponse
    {
        $record = $this->authorized($request, $token, $access);
        $gallery = $record->gallery;
        abort_unless($photo->gallery_id === $gallery->id && $gallery->customer_id, 404);
        $selected = $selections->toggle($gallery, $photo, $gallery->customer_id);
        $count = DB::table('photo_selections')->join('photos', 'photos.id', '=', 'photo_selections.photo_id')->where('photos.gallery_id', $gallery->id)->where('customer_id', $gallery->customer_id)->count();
        $access->log($gallery, $selected ? 'customer_selected_photo' : 'customer_deselected_photo', ['photo_id' => $photo->id]);

        return response()->json(compact('selected', 'count'));
    }

    public function image(Request $request, string $token, Photo $photo, GalleryAccessService $access): Response
    {
        $record = $this->authorized($request, $token, $access);
        abort_unless($photo->gallery_id === $record->gallery_id && $photo->is_proof, 404);
        $path = $photo->preview_path ?: $photo->thumbnail_path;
        abort_unless($path && PhotoStorage::disk($path)->exists($path), 404);

        return response(PhotoStorage::disk($path)->get($path), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private,no-store']);
    }

    public function submit(Request $request, string $token, GalleryAccessService $access, PhotoSelectionService $selections): RedirectResponse
    {
        $record = $this->authorized($request, $token, $access);
        $gallery = $record->gallery;
        $selections->complete($gallery, $gallery->customer_id);
        $access->log($gallery, 'selection_submitted', ['count' => $gallery->fresh()->submitted_selection_count]);

        return back()->with('success', 'Your selected photos have been sent to your photographer for editing. Thank you!')->with('selection_success', true);
    }

    private function authorized(Request $request, string $plain, GalleryAccessService $access): GalleryAccessToken
    {
        $record = $access->resolve($plain, 'selection');
        abort_unless($record && $record->isUsable(), 403);
        abort_if($record->gallery->pin_hash && ! $request->session()->get('gallery_token_pin.'.$record->id), 403);

        return $record;
    }
}
