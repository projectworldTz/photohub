<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Role;
use App\Models\User;
use App\Services\StorageQuotaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminBusinessController extends Controller
{
    public function create(): View
    {
        $this->guard();

        return view('admin.business-form', ['business' => new Business]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard();
        $data = $this->validated($request);
        DB::transaction(function () use ($data) {
            $owner = User::create(['name' => $data['owner_name'], 'email' => $data['owner_email'], 'phone' => $data['phone'], 'password' => $data['password']]);
            $business = Business::create(['name' => $data['name'], 'slug' => $this->slug($data['name']), 'email' => $data['email'], 'phone' => $data['phone'], 'city' => $data['city'], 'country' => $data['country'], 'category' => $data['category'], 'currency' => strtoupper($data['currency']), 'timezone' => $data['timezone'], 'trial_ends_at' => now()->addDays(14)]);
            $membership = BusinessUser::create(['business_id' => $business->id, 'user_id' => $owner->id, 'employee_number' => 'EMP-000001', 'job_title' => 'Business Owner', 'joined_at' => today()]);
            $membership->roles()->attach(Role::where('slug', 'owner')->firstOrFail());
        });

        return redirect()->route('admin.index')->with('success', 'Business and owner created.');
    }

    public function show(Business $business): View
    {
        $this->guard();

        return view('admin.business-show', ['business' => $business->load(['users', 'activityLogs.user']), 'counts' => ['customers' => DB::table('customers')->where('business_id', $business->id)->count(), 'bookings' => DB::table('bookings')->where('business_id', $business->id)->count(), 'galleries' => DB::table('galleries')->where('business_id', $business->id)->count(), 'photos' => DB::table('photos')->where('business_id', $business->id)->count(), 'revenue' => DB::table('payments')->where('business_id', $business->id)->where('status', 'completed')->sum('amount')]]);
    }

    public function edit(Business $business): View
    {
        $this->guard();

        return view('admin.business-form', compact('business'));
    }

    public function update(Request $request, Business $business): RedirectResponse
    {
        $this->guard();
        $business->update($request->validate(['name' => 'required|string|max:150', 'email' => ['required', 'email', Rule::unique('businesses')->ignore($business)], 'phone' => 'required|string|max:30', 'city' => 'required|string|max:100', 'country' => 'required|string|max:100', 'category' => 'required|string|max:100', 'currency' => 'required|string|size:3', 'timezone' => 'required|timezone']));

        return redirect()->route('admin.businesses.show', $business)->with('success', 'Business updated.');
    }

    public function destroy(Business $business): RedirectResponse
    {
        $this->guard();
        $business->update(['status' => 'suspended']);
        $business->delete();

        return redirect()->route('admin.index')->with('success', 'Business archived. Its records remain recoverable.');
    }

    public function storage(Request $request, Business $business, StorageQuotaService $quota): RedirectResponse
    {
        $this->guard();
        $data = $request->validate(['storage_limit_gb' => 'required|integer|min:1|max:1048576']);
        $quota->locked($business, function ($locked) use ($data) {
            $locked->forceFill(['storage_limit_bytes' => $data['storage_limit_gb'] * StorageQuotaService::GB])->save();
        });

        return back()->with('success', 'Storage allocation updated for this studio. Existing files are retained.');
    }

    public function restore(int $id): RedirectResponse
    {
        $this->guard();
        $business = Business::withTrashed()->findOrFail($id);
        $business->restore();
        $business->update(['status' => 'active']);

        return back()->with('success', 'Business restored and activated.');
    }

    private function validated(Request $request): array
    {
        return $request->validate(['name' => 'required|string|max:150', 'owner_name' => 'required|string|max:150', 'owner_email' => 'required|email|unique:users,email', 'email' => 'required|email|unique:businesses,email', 'phone' => 'required|string|max:30', 'city' => 'required|string|max:100', 'country' => 'required|string|max:100', 'category' => 'required|string|max:100', 'currency' => 'required|string|size:3', 'timezone' => 'required|timezone', 'password' => 'required|string|min:8']);
    }

    private function slug(string $name): string
    {
        $base = Str::slug($name) ?: 'studio';
        $slug = $base;
        for ($i = 2; Business::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    private function guard(): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);
    }
}
