<?php

namespace App\Http\Middleware;

use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveBusiness
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401);
        if ($user->is_super_admin) {
            return $next($request);
        }

        $businessId = (int) $request->session()->get('business_id');
        $membership = $businessId ? $user->membershipFor($businessId) : null;
        if (! $membership) {
            $membership = $user->memberships()->where('status', 'active')->first();
            abort_unless($membership, 403, 'You do not have an active business membership.');
            $businessId = $membership->business_id;
            $request->session()->put('business_id', $businessId);
        }

        $business = Business::find($businessId);
        abort_unless($business && $business->status === 'active', 403, 'This business account is not active.');
        app()->instance('currentBusiness', $business);
        view()->share('currentBusiness', $business);

        return $next($request);
    }
}
