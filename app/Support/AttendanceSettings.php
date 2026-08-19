<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\Setting;
use App\Models\Shift;
use Carbon\CarbonInterface;

/**
 * Centralizes attendance-related configuration (work hours & office geofence)
 * so it can be edited by admins at runtime instead of being hardcoded.
 *
 * Values are persisted in the {@see Setting} key-value store and cached there.
 */
class AttendanceSettings
{
    /** @return array{check_in: string, check_out: string, late_threshold: string} */
    public const DEFAULT_OFFICE_HOURS = [
        'check_in' => '08:00',
        'check_out' => '17:00',
        'late_threshold' => '08:05',
    ];

    public const DEFAULT_RADIUS_METERS = 100;

    /** @var array{break_start: string, break_end: string} */
    public const DEFAULT_BREAK = [
        'break_start' => '12:00',
        'break_end' => '13:00',
    ];

    /**
     * The configured office hours.
     *
     * @return array{check_in: string, check_out: string, late_threshold: string}
     */
    public static function officeHours(): array
    {
        return array_merge(
            self::DEFAULT_OFFICE_HOURS,
            Setting::get('office_hours', []) ?? [],
        );
    }

    /**
     * The configured break window (used when shifts are disabled but the break
     * feature is enabled).
     *
     * @return array{break_start: string, break_end: string}
     */
    public static function breakWindow(): array
    {
        return array_merge(
            self::DEFAULT_BREAK,
            Setting::get('break_window', []) ?? [],
        );
    }

    /**
     * Resolve the shift that applies to an employee on a given date. Returns
     * null when the shift feature is disabled or no shift is assigned.
     */
    public static function resolveShift(Employee $employee, CarbonInterface $date): ?Shift
    {
        if (! FeatureSettings::shiftEnabled()) {
            return null;
        }

        return $employee->shiftForDate($date);
    }

    /**
     * The configured office geofence, owned solely by the settings store and
     * edited through the Attendance Settings screen.
     *
     * A null latitude or longitude disables the geofence check entirely — that
     * is the state the admin lands in either by never configuring a location or
     * by unticking the geofence toggle. The radius survives being disabled so
     * re-enabling it restores the previous value.
     *
     * @return array{latitude: float|null, longitude: float|null, radius_meters: float}
     */
    public static function officeLocation(): array
    {
        $stored = Setting::get('office_location');

        if (! is_array($stored)) {
            return [
                'latitude' => null,
                'longitude' => null,
                'radius_meters' => (float) self::DEFAULT_RADIUS_METERS,
            ];
        }

        $latitude = $stored['latitude'] ?? null;
        $longitude = $stored['longitude'] ?? null;

        return [
            'latitude' => $latitude !== null ? (float) $latitude : null,
            'longitude' => $longitude !== null ? (float) $longitude : null,
            'radius_meters' => (float) ($stored['radius_meters'] ?? self::DEFAULT_RADIUS_METERS),
        ];
    }
}
