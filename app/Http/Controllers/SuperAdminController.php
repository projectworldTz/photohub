<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Photo;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\BusinessAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SuperAdminController extends Controller
{
    private function guard(): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);
    }

    public function index(): View
    {
        $this->guard();

        return view('admin.index', ['businesses' => Business::withCount('users')->latest()->paginate(30), 'archived' => Business::onlyTrashed()->latest('deleted_at')->get(), 'plans' => SubscriptionPlan::where('is_active', true)->get(), 'stats' => ['businesses' => Business::withTrashed()->count(), 'active' => Business::where('status', 'active')->count(), 'users' => User::count(), 'customers' => Customer::count(), 'photos' => Photo::count(), 'storage_mb' => round(Photo::sum('file_size') / 1048576, 1), 'monthly_revenue' => Payment::whereMonth('payment_date', now()->month)->sum('amount')]]);
    }

    public function status(Request $r, Business $business): RedirectResponse
    {
        $this->guard();
        $business->update($r->validate(['status' => 'required|in:active,suspended']));

        return back()->with('success', 'Business status updated.');
    }

    public function plans(): View
    {
        $this->guard();

        return view('admin.plans', ['plans' => SubscriptionPlan::all()]);
    }

    public function plan(Request $r): RedirectResponse
    {
        $this->guard();
        SubscriptionPlan::create($r->validate(['name' => 'required', 'slug' => 'required|unique:subscription_plans', 'price' => 'required|numeric|min:0', 'storage_limit_mb' => 'required|integer|min:1', 'gallery_limit' => 'required|integer|min:1']) + ['is_active' => true]);

        return back();
    }

    public function updatePlan(Request $request, SubscriptionPlan $plan): RedirectResponse
    {
        $this->guard();
        $plan->update($request->validate(['name' => 'required|string|max:100', 'price' => 'required|numeric|min:0', 'storage_limit_mb' => 'required|integer|min:1', 'gallery_limit' => 'required|integer|min:1', 'is_active' => 'boolean']));

        return back()->with('success', 'Plan updated.');
    }

    public function settings(): View
    {
        $this->guard();

        return view('admin.settings', ['settings' => PlatformSetting::pluck('value', 'key')]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $this->guard();
        $data = $request->validate(['platform_name' => 'required|string|max:100', 'support_email' => 'required|email', 'default_trial_days' => 'required|integer|min:0|max:365', 'maintenance_notice' => 'nullable|string|max:1000']);
        foreach ($data as $key => $value) {
            PlatformSetting::updateOrCreate(['key' => $key], ['value' => ['value' => $value]]);
        }

        return back()->with('success', 'Platform settings updated.');
    }

    public function broadcast(Request $request): RedirectResponse
    {
        $this->guard();
        $data = $request->validate(['title' => 'required|string|max:150', 'message' => 'required|string|max:2000']);
        User::whereHas('memberships', fn ($q) => $q->where('status', 'active'))->each(fn (User $user) => $user->notify(new BusinessAlert($data['title'], $data['message'])));

        return back()->with('success', 'System notification queued for active business users.');
    }

    public function subscribe(Request $r, Business $business): RedirectResponse
    {
        $this->guard();
        $data = $r->validate(['subscription_plan_id' => 'required|exists:subscription_plans,id', 'months' => 'required|integer|min:1|max:120', 'complimentary' => 'boolean']);
        Subscription::where('business_id', $business->id)->where('status', 'active')->update(['status' => 'replaced']);
        Subscription::create($data + ['business_id' => $business->id, 'starts_at' => now(), 'ends_at' => now()->addMonths($data['months']), 'status' => 'active']);

        return back()->with('success', 'Subscription updated.');
    }
}
