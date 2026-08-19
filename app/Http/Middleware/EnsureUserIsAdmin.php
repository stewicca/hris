<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // ponytail: back-office is admin-only. Employees use the API portal
        // (frontend/apps/employee), so they never need these web routes.
        abort_unless((bool) $request->user()?->is_admin, 403);

        return $next($request);
    }
}
