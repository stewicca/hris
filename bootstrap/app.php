<?php

use App\Http\Middleware\EnsureKioskFeatureEnabled;
use App\Http\Middleware\EnsureValidKioskDevice;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogApiRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        // Applies the 'api' rate limiter (AppServiceProvider) to routes/api.php.
        // Laravel has not done this by default since v11.
        $middleware->throttleApi();

        // Authenticate kiosk device before running the per-device rate limiter.
        $middleware->prependToPriorityList(ThrottleRequests::class, EnsureKioskFeatureEnabled::class);
        $middleware->prependToPriorityList(ThrottleRequests::class, EnsureValidKioskDevice::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->validateCsrfTokens(except: ['api/*']);

        $middleware->append(LogApiRequests::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
