<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureImpersonationIsReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->has('impersonator_id') && ! $request->isMethodSafe()) {
            abort(403, 'View-as-owner mode is read-only. Return to platform administration to make changes.');
        }

        return $next($request);
    }
}
