<?php

namespace App\Http\Middleware;

use App\Models\KioskDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the terminal behind a kiosk request.
 *
 * The kiosk routes carry no employee session — the face supplies identity and
 * this supplies provenance. The token travels in a header rather than in the
 * URL deliberately: a kiosk is a screen strangers stand in front of, and
 * anything in the address bar can be photographed, read over a shoulder, or
 * picked out of an nginx access log.
 */
class EnsureValidKioskDevice
{
    /**
     * Request attribute holding the resolved device for downstream handlers.
     */
    public const string ATTRIBUTE = 'kiosk_device';

    public function handle(Request $request, Closure $next): Response
    {
        $device = KioskDevice::findByToken((string) $request->header('X-Kiosk-Token', ''));

        if ($device === null) {
            abort(401, 'Perangkat kiosk tidak dikenali.');
        }

        if (! $device->allowsIp($request->ip())) {
            abort(403, 'Perangkat kiosk berada di jaringan yang tidak diizinkan.');
        }

        $device->updateQuietly(['last_seen_at' => now()]);

        $request->attributes->set(self::ATTRIBUTE, $device);

        return $next($request);
    }
}
