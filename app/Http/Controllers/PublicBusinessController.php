<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Lead;
use App\Models\Package;
use App\Models\Photo;
use App\Models\PortfolioCategory;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PublicBusinessController extends Controller
{
    public function show(string $slug): View
    {
        $b = Business::where('slug', $slug)->where('status', 'active')->firstOrFail();

        return view('public.business', ['business' => $b, 'packages' => Package::forBusiness($b->id)->where('is_active', true)->get(), 'portfolio' => PortfolioCategory::with(['photos' => fn ($query) => $query->whereHas('gallery', fn ($g) => $g->where('expires_at', '>', now())->whereNotIn('status', ['expired', 'archived']))->with('gallery')])->forBusiness($b->id)->get()]);
    }

    public function book(Request $r, string $slug, NotificationService $notifications): RedirectResponse
    {
        $b = Business::where('slug', $slug)->where('status', 'active')->firstOrFail();
        $d = $r->validate(['name' => 'required|string|max:150', 'phone' => 'required|string|max:30', 'email' => 'nullable|email', 'package_id' => ['nullable', Rule::exists('packages', 'id')->where('business_id', $b->id)->where('is_active', true)], 'event_type' => 'required|string|max:100', 'event_date' => 'required|date|after_or_equal:today', 'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|after:start_time', 'message' => 'nullable|string|max:2000']);
        Lead::create($d + ['business_id' => $b->id, 'source' => 'public_booking', 'status' => 'new']);
        $notifications->business($b, 'New booking request', $d['name'].' requested '.$d['event_type'].' on '.$d['event_date'], route('leads.index'));

        return back()->with('success', 'Your booking request has been sent.');
    }

    public function photo(string $slug, Photo $photo): Response
    {
        $business = Business::where('slug', $slug)->where('status', 'active')->firstOrFail();
        abort_unless($photo->business_id === $business->id && $photo->portfolioCategories()->exists(), 404);
        abort_unless($photo->gallery && ! $photo->gallery->isExpired() && $photo->gallery->status !== 'archived', 410);
        $path = $photo->preview_path ?: $photo->thumbnail_path;
        abort_unless($path && \App\Services\PhotoStorage::disk($path)->exists($path), 404);

        return response(\App\Services\PhotoStorage::disk($path)->get($path), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private,no-store']);
    }

    public function logo(string $slug): Response
    {
        $business = Business::where('slug', $slug)->where('status', 'active')->firstOrFail();
        abort_unless($business->logo_path && Storage::disk('local')->exists($business->logo_path), 404);

        return response(Storage::disk('local')->get($business->logo_path), 200, ['Content-Type' => Storage::disk('local')->mimeType($business->logo_path), 'Cache-Control' => 'public,max-age=3600']);
    }
}
