<?php

namespace App\Http\Middleware;

use App\Support\FeatureSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the kiosk routes when the terminal feature is disabled.
 *
 * Returns a 404 (rather than 403) so a disabled module looks like it never
 * existed, avoiding leakage of its route surface.
 */
class EnsureKioskFeatureEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(FeatureSettings::kioskEnabled(), 404);

        return $next($request);
    }
}
