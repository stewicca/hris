<?php

namespace App\Actions\Attendance;

use App\Support\AttendanceSettings;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class VerifyAttendanceGps
{
    public function handle(
        float $latitude,
        float $longitude,
        float $accuracy,
        int $gpsTimestampMs,
    ): void {
        $this->validateIntegrity($accuracy, $gpsTimestampMs);
        $this->validateGeofence($latitude, $longitude);
    }

    private function validateIntegrity(float $accuracy, int $gpsTimestampMs): void
    {
        $maxAccuracy = config('attendance.gps.max_accuracy_meters');

        if ($accuracy > $maxAccuracy) {
            throw ValidationException::withMessages([
                'accuracy' => [
                    "Akurasi GPS terlalu rendah ({$accuracy}m). Pastikan GPS aktif dan berada di luar ruangan.",
                ],
            ]);
        }

        $maxAge = config('attendance.gps.max_age_seconds');
        $gpsAgeSeconds = abs(now()->diffInSeconds(Carbon::createFromTimestampMs($gpsTimestampMs)));

        if ($gpsAgeSeconds > $maxAge) {
            throw ValidationException::withMessages([
                'gps_timestamp' => [
                    'Data lokasi sudah kedaluwarsa. Refresh lokasi GPS Anda dan coba lagi.',
                ],
            ]);
        }
    }

    private function validateGeofence(float $latitude, float $longitude): void
    {
        $office = AttendanceSettings::officeLocation();

        if ($office['latitude'] === null || $office['longitude'] === null) {
            return;
        }

        $radius = $office['radius_meters'];
        $distance = $this->haversineMeters($latitude, $longitude, $office['latitude'], $office['longitude']);

        if ($distance > $radius) {
            throw ValidationException::withMessages([
                'location' => [
                    sprintf(
                        'Anda berada %.0f meter dari kantor. Absensi hanya bisa dilakukan dalam radius %.0f meter.',
                        $distance,
                        $radius,
                    ),
                ],
            ]);
        }
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
