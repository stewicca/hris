<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Rate limits for the API surface.
     *
     * The employee portal authenticates against /api/login, which is a plain
     * controller rather than a Fortify route — so Fortify's login limiter does
     * not cover it and these definitions are the only thing standing between
     * that endpoint and unlimited password guessing.
     *
     * Client IPs arrive via X-Forwarded-For. docker/nginx.prod.conf resolves
     * that to a single trusted value before it reaches PHP, so a client cannot
     * spoof the header to get a fresh bucket.
     */
    protected function configureRateLimiting(): void
    {
        // General ceiling for every /api route.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));

        // Login: tight per credential+IP so guessing one account is slow, plus
        // a looser per-IP cap so an attacker cannot simply rotate usernames.
        RateLimiter::for('api-login', function (Request $request): array {
            $username = Str::transliterate(Str::lower((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by($username.'|'.$request->ip()),
                Limit::perMinute(20)->by('ip|'.$request->ip()),
            ];
        });

        // Face endpoints upload an image and run CPU-bound ArcFace inference.
        // A handful per minute is far above real use and well below what it
        // takes to saturate the box.
        RateLimiter::for('face', fn (Request $request) => Limit::perMinute(12)
            ->by($request->user()?->id ?: $request->ip()));

        // Kiosk terminals need their own bucket. The face limiter keys by IP
        // for unauthenticated callers, which would make one terminal's dozen
        // scans the whole office's ration during a shift change. Keyed per
        // device and set well above what one queue in front of one camera can
        // physically produce.
        RateLimiter::for('kiosk', fn (Request $request) => Limit::perMinute(30)
            ->by('kiosk|'.(string) $request->header('X-Kiosk-Token', $request->ip())));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
