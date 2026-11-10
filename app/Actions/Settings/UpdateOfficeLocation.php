<?php

namespace App\Actions\Settings;

use App\Models\Setting;

class UpdateOfficeLocation
{
    /**
     * @param  array{latitude?: float|string|null, longitude?: float|string|null, radius_meters: int|string}  $validated
     */
    public function handle(bool $enabled, array $validated): void
    {
        $hasCoords = $enabled && ($validated['latitude'] ?? null) !== null && ($validated['longitude'] ?? null) !== null;

        Setting::set('office_location', [
            'latitude' => $hasCoords ? (float) $validated['latitude'] : null,
            'longitude' => $hasCoords ? (float) $validated['longitude'] : null,
            'radius_meters' => (int) $validated['radius_meters'],
        ]);
    }
}
