<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    public function start(Request $request, Business $business): RedirectResponse
    {
        $administrator = $request->user();
        abort_unless($administrator?->is_super_admin && ! $request->session()->has('impersonator_id'), 403);
        abort_unless($business->status === 'active', 422, 'Only active businesses can be viewed.');

        $membership = BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->where('slug', 'owner'))
            ->with('user')
            ->first();
        abort_unless($membership?->user?->is_active, 422, 'This business has no active owner account.');

        ActivityLog::create(['business_id' => $business->id, 'user_id' => $administrator->id, 'action' => 'platform.impersonation_started', 'subject_type' => User::class, 'subject_id' => $membership->user_id, 'ip_address' => $request->ip()]);
        $request->session()->regenerate();
        $request->session()->put(['impersonator_id' => $administrator->id, 'impersonated_business_id' => $business->id]);
        Auth::login($membership->user);
        $request->session()->put('business_id', $business->id);

        return redirect()->route('dashboard')->with('success', "You are viewing {$business->name} as {$membership->user->name} in read-only mode.");
    }

    public function stop(Request $request): RedirectResponse
    {
        $administrator = User::query()->whereKey($request->session()->get('impersonator_id'))->where('is_super_admin', true)->where('is_active', true)->first();
        abort_unless($administrator, 403);
        $businessId = (int) $request->session()->get('impersonated_business_id');

        Auth::login($administrator);
        $request->session()->forget(['impersonator_id', 'impersonated_business_id', 'business_id']);
        $request->session()->regenerate();
        if ($businessId) {
            ActivityLog::create(['business_id' => $businessId, 'user_id' => $administrator->id, 'action' => 'platform.impersonation_ended', 'subject_type' => User::class, 'subject_id' => $administrator->id, 'ip_address' => $request->ip()]);
        }

        return redirect()->route('admin.index')->with('success', 'Returned to platform administration.');
    }
}
