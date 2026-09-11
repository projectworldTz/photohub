<?php

namespace App\Http\Middleware;

use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthenticateStudioSync
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('photohub.mode') === 'cloud', 404);
        abort_unless($request->isSecure() || config('photohub.allow_http'), 403);
        $plain = $request->bearerToken();
        abort_unless($plain && strlen($plain) >= 48, 401);
        $token = DB::table('studio_api_tokens')->where('token_hash', hash('sha256', $plain))->whereNull('revoked_at')->first();
        abort_unless($token, 401);
        $business = Business::find($token->business_id);
        abort_unless($business && $business->status === 'active', 403);
        $business->forceFill(['cloud_last_seen_at' => now()])->saveQuietly();
        $request->attributes->set('sync_business', $business);

        return $next($request);
    }
}
