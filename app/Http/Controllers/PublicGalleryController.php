<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Gallery;
use App\Models\Order;
use App\Models\Photo;
use App\Services\OrderService;
use App\Services\PhotoSelectionService;
use App\Services\ZipDownloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicGalleryController extends Controller
{
    public function show(Request $r, string $code): View
    {
        $g = Gallery::with('business')->where('code', $code)->whereIn('status', ['published', 'awaiting_selection', 'selection_completed', 'final', 'delivered'])->firstOrFail();
        abort_if($g->expires_at?->isPast(), 410, 'This gallery has expired.');
        if ($g->privacy !== 'public' && ! $this->customerAuthorized($r, $g)) {
            return view('public.gallery-unlock', ['gallery' => $g]);
        }

        $paidPhotoIds = Order::where('gallery_id', $g->id)->where('payment_status', 'paid')->with('items:id,order_id,photo_id')->get()->flatMap->items->pluck('photo_id');
        $selectedPhotoIds = $g->customer_id
            ? \DB::table('photo_selections')->join('photos', 'photos.id', '=', 'photo_selections.photo_id')->where('photos.gallery_id', $g->id)->where('customer_id', $g->customer_id)->pluck('photo_id')
            : collect();

        return view('public.gallery', ['gallery' => $g, 'photos' => $g->photos()->where('status', 'ready')->paginate(30), 'paidPhotoIds' => $paidPhotoIds, 'selectedPhotoIds' => $selectedPhotoIds]);
    }

    public function unlock(Request $r, string $code): RedirectResponse
    {
        $g = Gallery::where('code', $code)->firstOrFail();
        $r->validate(['pin' => 'required|string']);
        abort_unless($g->pin_hash && Hash::check($r->pin, $g->pin_hash), 422, 'The gallery PIN is incorrect.');
        $r->session()->put('gallery_access.'.$g->id, true);

        return redirect()->route('public.gallery', $code);
    }

    public function image(Request $r, string $code, Photo $photo): Response
    {
        $g = $this->access($r, $code);
        abort_unless($photo->gallery_id === $g->id, 404);
        $path = $photo->preview_path ?: $photo->thumbnail_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private,max-age=3600']);
    }

    public function select(Request $r, string $code, Photo $photo, PhotoSelectionService $s): JsonResponse
    {
        $g = $this->access($r, $code);
        abort_unless($g->customer_id, 422, 'Selections require a customer-linked gallery.');
        abort_unless($this->customerAuthorized($r, $g), 403, 'Customer authentication or the gallery PIN is required to select photos.');

        return response()->json(['selected' => $s->toggle($g, $photo, $g->customer_id)]);
    }

    public function favorite(Request $r, string $code, Photo $photo): JsonResponse
    {
        $g = $this->access($r, $code);
        abort_unless($photo->gallery_id === $g->id && $g->customer_id, 422);
        abort_unless($this->customerAuthorized($r, $g), 403, 'Customer authentication or the gallery PIN is required to favorite photos.');
        $where = ['photo_id' => $photo->id, 'customer_id' => $g->customer_id];
        $exists = \DB::table('photo_favorites')->where($where)->exists();
        $exists ? \DB::table('photo_favorites')->where($where)->delete() : \DB::table('photo_favorites')->insert($where + ['favorited_at' => now()]);

        return response()->json(['favorited' => ! $exists]);
    }

    public function completeSelection(Request $request, string $code, PhotoSelectionService $service): RedirectResponse
    {
        $gallery = $this->access($request, $code);
        abort_unless($gallery->customer_id, 422, 'Selections require a customer-linked gallery.');
        abort_unless($this->customerAuthorized($request, $gallery), 403, 'Customer authentication or the gallery PIN is required.');
        $invoice = $service->complete($gallery, $gallery->customer_id);

        return back()->with('success', $invoice ? 'Selection completed and an invoice was created for extra photos.' : 'Your selection is complete. The studio has been notified.');
    }

    public function order(Request $r, string $code, OrderService $service): RedirectResponse
    {
        $gallery = $this->access($r, $code);
        $data = $r->validate(['photos' => 'required|array|min:1', 'photos.*' => 'integer']);
        $order = $service->create($gallery, $data['photos'], $gallery->customer_id);

        return redirect()->route('public.order', [$code, $order]);
    }

    public function orderShow(Request $r, string $code, Order $order): View
    {
        $gallery = $this->access($r, $code);
        abort_unless($order->gallery_id === $gallery->id, 404);

        return view('public.order', ['order' => $order->load('items'), 'gallery' => $gallery]);
    }

    public function download(Request $r, string $code, Photo $photo): Response
    {
        $gallery = $this->access($r, $code);
        abort_unless($photo->gallery_id === $gallery->id && $photo->is_downloadable && $gallery->downloads_enabled, 403, 'This photo is not available for download.');
        abort_unless($this->isPaid($gallery, [$photo->id]), 402, 'Payment is required before downloading this photo.');
        abort_unless(Storage::disk('local')->exists($photo->original_path), 404);

        return response(Storage::disk('local')->get($photo->original_path), 200, [
            'Content-Type' => $photo->mime_type,
            'Content-Disposition' => 'attachment; filename="'.addslashes(basename($photo->filename)).'"',
        ]);
    }

    public function zip(Request $r, string $code, ZipDownloadService $service): BinaryFileResponse
    {
        $gallery = $this->access($r, $code);
        $data = $r->validate(['photos' => 'required|array|min:1', 'photos.*' => 'integer']);
        $paid = $this->isPaid($gallery, $data['photos']);
        $path = $service->create($gallery, $data['photos'], $paid);

        return response()->download($path, $gallery->code.'.zip')->deleteFileAfterSend(true);
    }

    public function zipAll(Request $request, string $code, ZipDownloadService $service): BinaryFileResponse
    {
        $gallery = $this->access($request, $code);
        $ids = $gallery->photos()->where('is_downloadable', true)->pluck('id');
        if ($gallery->payment_required) {
            $paid = Order::where('gallery_id', $gallery->id)->where('payment_status', 'paid')->with('items:id,order_id,photo_id')->get()->flatMap->items->pluck('photo_id')->unique();
            $ids = $ids->intersect($paid);
        }
        abort_if($ids->isEmpty(), 403, 'No authorized photos are available for download.');
        $path = $service->create($gallery, $ids->all(), true);

        return response()->download($path, $gallery->code.'-all.zip')->deleteFileAfterSend(true);
    }

    private function isPaid(Gallery $gallery, array $photoIds): bool
    {
        if (! $gallery->payment_required) {
            return true;
        }

        $paidIds = Order::where('gallery_id', $gallery->id)->where('payment_status', 'paid')
            ->whereHas('items', fn ($q) => $q->whereIn('photo_id', $photoIds))
            ->with('items:id,order_id,photo_id')->get()->flatMap->items->pluck('photo_id')->unique();

        return collect($photoIds)->unique()->diff($paidIds)->isEmpty();
    }

    private function access(Request $r, string $code): Gallery
    {
        $g = Gallery::where('code', $code)->firstOrFail();
        abort_if($g->expires_at?->isPast(), 410);
        abort_unless($g->privacy === 'public' || $this->customerAuthorized($r, $g), 403, 'You do not have permission to access this gallery.');

        return $g;
    }

    private function customerAuthorized(Request $request, Gallery $gallery): bool
    {
        if ($request->session()->get('gallery_access.'.$gallery->id)) {
            return true;
        }
        if (! $request->user() || ! $gallery->customer_id) {
            return false;
        }

        return Customer::whereKey($gallery->customer_id)->where('user_id', $request->user()->id)->exists();
    }
}
