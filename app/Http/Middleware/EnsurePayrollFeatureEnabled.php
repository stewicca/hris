<?php

namespace App\Http\Middleware;

use App\Support\FeatureSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks access to the payroll (penggajian) routes when the feature is disabled.
 *
 * Returns a 404 (rather than 403) so a disabled module looks like it never
 * existed, avoiding leakage of its route surface.
 */
class EnsurePayrollFeatureEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(FeatureSettings::payrollEnabled(), 404);

        return $next($request);
    }
}
