<?php

namespace App\Http\Controllers;

use App\Models\BusinessSetting;
use App\Services\PhotoUploadService;
use App\Services\SubscriptionLimitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(SubscriptionLimitService $limits): View
    {
        $business = app('currentBusiness');

        return view('settings.index', ['settings' => BusinessSetting::forBusiness($business->id)->pluck('value', 'key'), 'usage' => $limits->usage($business)]);
    }

    public function update(Request $r): RedirectResponse
    {
        $b = app('currentBusiness');
        $r->merge(['gallery_downloads' => $r->boolean('gallery_downloads'), 'booking_deposit_required' => $r->boolean('booking_deposit_required')]);
        $d = $r->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:150', 'phone' => 'nullable|string|max:30', 'address' => 'nullable|string|max:255', 'city' => 'nullable|string|max:100', 'country' => 'nullable|string|max:100', 'description' => 'nullable|string|max:3000', 'currency' => 'required|string|size:3', 'timezone' => 'required|timezone', 'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096', 'watermark_logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096', 'gallery_downloads' => 'boolean', 'watermark_text' => 'nullable|string|max:100', 'watermark_opacity' => 'required|integer|min:1|max:100', 'watermark_position' => 'required|in:top_left,top_right,center,bottom_left,bottom_right', 'booking_deposit_required' => 'boolean']);
        $profile = collect($d)->only(['name', 'email', 'phone', 'address', 'city', 'country', 'description', 'currency', 'timezone'])->all();
        $profile['currency'] = strtoupper($profile['currency']);
        $uploads = app(PhotoUploadService::class)->storeFiles($b, array_filter(['logo' => $r->file('logo'), 'watermark_logo' => $r->file('watermark_logo')]), 'branding');
        if ($r->hasFile('logo')) {
            $profile['logo_path'] = $uploads['logo'];
        }
        $b->update($profile);
        if ($r->hasFile('watermark_logo')) {
            $d['watermark_logo'] = $uploads['watermark_logo'];
        }
        foreach (collect($d)->except(['name', 'email', 'phone', 'address', 'city', 'country', 'description', 'logo', 'currency', 'timezone'])->all() as $key => $value) {
            BusinessSetting::updateOrCreate(['business_id' => $b->id, 'key' => $key], ['value' => ['value' => $value]]);
        }

        return back()->with('success', 'Settings updated.');
    }
}
