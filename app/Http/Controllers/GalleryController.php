<?php

namespace App\Http\Controllers;

use App\Events\GalleryPublished;
use App\Http\Requests\GalleryRequest;
use App\Http\Requests\UploadPhotosRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Shoot;
use App\Services\GalleryAccessService;
use App\Services\PhotoProcessingService;
use App\Services\PhotoStorage;
use App\Services\QRCodeService;
use App\Services\SubscriptionLimitService;
use App\Services\ZipDownloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class GalleryController extends Controller
{
    public function index(Request $request): View
    {
        $businessId = app('currentBusiness')->id;
        $query = Gallery::with('customer')->withCount(['photos', 'finalPhotos'])->forBusiness($businessId)->latest();
        if ($request->filled('search')) {
            $term = $request->string('search')->trim()->value();
            $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('event', 'like', "%{$term}%")->orWhere('gallery_number', 'like', "%{$term}%"));
        }
        foreach (['status', 'type', 'customer_id'] as $field) {
            if ($request->filled($field)) {
                if ($field === 'status' && $request->input($field) === 'expired') {
                    $query->where(fn ($q) => $q->where('expires_at', '<=', now())->orWhere('status', 'expired'));
                } else {
                    $query->where($field, $request->input($field));
                }
            }
        }

        return view('galleries.index', ['galleries' => $query->paginate(20)->withQueryString(), 'customers' => Customer::forBusiness($businessId)->orderBy('first_name')->get()]);
    }

    public function create(Request $request): View
    {
        $businessId = app('currentBusiness')->id;

        $defaults = ['type' => 'final_delivery', 'privacy' => 'private', 'status' => 'draft', 'watermark_enabled' => true];
        if ($request->filled('shoot_id')) {
            $shoot = Shoot::forBusiness($businessId)->findOrFail($request->integer('shoot_id'));
            $defaults += ['shoot_id' => $shoot->id, 'booking_id' => $shoot->booking_id, 'customer_id' => $shoot->customer_id, 'name' => $shoot->event, 'event' => $shoot->event, 'event_date' => $shoot->shoot_date];
        }

        return view('galleries.form', ['gallery' => new Gallery($defaults), 'customers' => Customer::forBusiness($businessId)->get(), 'bookings' => Booking::with('customer')->forBusiness($businessId)->latest()->get(), 'shoots' => Shoot::with('customer')->forBusiness($businessId)->latest()->get()]);
    }

    public function store(GalleryRequest $r, SubscriptionLimitService $limits): RedirectResponse
    {
        $limits->assertCanCreateGallery(app('currentBusiness'));
        $d = $r->validated();
        $d['business_id'] = app('currentBusiness')->id;
        $d['gallery_number'] = 'GAL-'.now()->format('Y').'-'.str_pad((string) ((Gallery::forBusiness($d['business_id'])->max('id') ?? 0) + 1), 6, '0', STR_PAD_LEFT);
        $d['code'] = strtoupper(Str::random(10));
        $d['pin_hash'] = isset($d['pin']) ? Hash::make($d['pin']) : null;
        unset($d['pin']);
        $g = Gallery::create($d);

        return redirect()->route($g->type === 'final_delivery' ? 'galleries.workflow' : 'galleries.show', $g);
    }

    public function show(Gallery $gallery): View
    {
        $this->guard($gallery);

        return view('galleries.show', ['gallery' => $gallery->load('customer'), 'photos' => $gallery->photos()->withCount(['selections', 'favorites'])->paginate(30)]);
    }

    public function edit(Gallery $gallery): View
    {
        $this->guard($gallery);

        $businessId = app('currentBusiness')->id;

        return view('galleries.form', ['gallery' => $gallery, 'customers' => Customer::forBusiness($businessId)->get(), 'bookings' => Booking::with('customer')->forBusiness($businessId)->latest()->get(), 'shoots' => Shoot::with('customer')->forBusiness($businessId)->latest()->get()]);
    }

    public function update(GalleryRequest $request, Gallery $gallery): RedirectResponse
    {
        $this->guard($gallery);
        $data = $request->validated();
        if (! empty($data['pin'])) {
            $data['pin_hash'] = Hash::make($data['pin']);
        }
        unset($data['pin']);
        $wasPublished = $gallery->status === 'published';
        $gallery->update($data);
        if (! $wasPublished && $gallery->status === 'published') {
            GalleryPublished::dispatch($gallery);
        }

        return redirect()->route('galleries.show', $gallery)->with('success', 'Gallery updated.');
    }

    public function destroy(Gallery $gallery): RedirectResponse
    {
        $this->guard($gallery);
        abort_unless(auth()->user()->hasPermission('galleries.manage'), 403);
        $gallery->update(['status' => 'archived']);
        $gallery->delete();

        return redirect()->route('galleries.index')->with('success', 'Gallery archived.');
    }

    public function extend(Request $request, Gallery $gallery): RedirectResponse
    {
        $this->guard($gallery);
        abort_unless($request->user()->hasPermission('galleries.manage'), 403);
        $days = (int) $request->validate(['days' => 'required|integer|in:7,30'])['days'];
        DB::transaction(function () use ($gallery, $days) {
            $locked = Gallery::lockForUpdate()->findOrFail($gallery->id);
            $previous = $locked->expires_at;
            $expires = ($previous && $previous->isFuture() ? $previous->copy() : now())->addDays($days);
            $locked->update(['expires_at' => $expires]);
            // Keep links that followed the gallery date aligned; independent
            // shorter deadlines and revoked links retain their restrictions.
            $locked->accessTokens()->whereNull('revoked_at')->where('expires_at', $previous)->update(['expires_at' => $expires, 'notified_30_at' => null, 'notified_7_at' => null, 'notified_1_at' => null]);
        });

        return back()->with('success', 'Gallery expiry extended.');
    }

    public function upload(UploadPhotosRequest $r, Gallery $gallery, PhotoProcessingService $s, SubscriptionLimitService $limits, GalleryAccessService $access): RedirectResponse|JsonResponse
    {
        $this->guard($gallery);
        $limits->assertCanStore(app('currentBusiness'), collect($r->file('photos'))->sum(fn ($file) => $file->getSize()));
        $s->storeBatch($gallery, $r->file('photos'), $r->boolean('is_final'));
        if (! $r->boolean('is_final') && $gallery->status === 'draft') {
            $gallery->update(['status' => 'proofs_ready']);
        }

        if ($r->expectsJson()) {
            return response()->json(['uploaded' => count($r->file('photos'))]);
        }

        return back()->with('success', 'Photos uploaded and processed.');
    }

    public function preview(Gallery $gallery, Photo $photo): Response
    {
        $this->guard($gallery);
        abort_unless($photo->gallery_id === $gallery->id, 404);
        $path = $photo->preview_path ?: $photo->thumbnail_path;
        abort_unless($path && PhotoStorage::disk($path)->exists($path), 404);

        return response(PhotoStorage::disk($path)->get($path), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function download(Gallery $gallery, Photo $photo)
    {
        $this->guard($gallery);
        abort_unless($photo->gallery_id === $gallery->id && $gallery->downloads_enabled && $photo->is_downloadable, 403, 'Payment and gallery download permission are required.');

        return PhotoStorage::disk($photo->original_path)->download($photo->original_path, $photo->filename);
    }

    public function zip(Request $request, Gallery $gallery, ZipDownloadService $service)
    {
        $this->guard($gallery);
        $ids = $request->validate(['photos' => 'required|array|min:1', 'photos.*' => 'integer'])['photos'];

        return response()->download($service->create($gallery, $ids, true), $gallery->code.'.zip')->deleteFileAfterSend(true);
    }

    public function qr(Gallery $gallery, QRCodeService $service): Response
    {
        $this->guard($gallery);

        return response($service->png(route('public.gallery', $gallery->code)), 200, ['Content-Type' => 'image/png', 'Content-Disposition' => 'inline; filename="'.$gallery->code.'-qr.png"']);
    }

    private function guard(Gallery $g): void
    {
        abort_unless($g->business_id === app('currentBusiness')->id, 404);
    }
}
