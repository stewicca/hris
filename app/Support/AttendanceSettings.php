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

    /**
     * The schedule that applied to an employee on a date: their shift when
     * shift mode is on and one is assigned, otherwise the global office hours
     * and break window.
     *
     * This is the reference clock every attendance-driven salary deduction is
     * measured against, which is what lets {@see PayrollDeductionSettings} hold
     * one ladder of tiers instead of one per shift.
     *
     * @return array{check_in: string, check_out: string, late_threshold: string, grace_minutes: int, break_enabled: bool, break_start: string|null, break_end: string|null, shift: Shift|null}
     */
    public static function scheduleFor(Employee $employee, CarbonInterface $date): array
    {
        $shift = self::resolveShift($employee, $date);
        $breakFeature = FeatureSettings::breakEnabled();

        if ($shift) {
            return [
                'check_in' => self::asClockTime($shift->check_in),
                'check_out' => self::asClockTime($shift->check_out),
                'late_threshold' => self::asClockTime($shift->late_threshold),
                'grace_minutes' => (int) $shift->grace_minutes,
                'break_enabled' => $breakFeature && $shift->break_enabled,
                'break_start' => $shift->break_start ? self::asClockTime($shift->break_start) : null,
                'break_end' => $shift->break_end ? self::asClockTime($shift->break_end) : null,
                'shift' => $shift,
            ];
        }

        $hours = self::officeHours();
        $break = self::breakWindow();

        return [
            'check_in' => self::asClockTime($hours['check_in']),
            'check_out' => self::asClockTime($hours['check_out']),
            'late_threshold' => self::asClockTime($hours['late_threshold']),
            // Grace is a shift-only concept; the global late threshold already
            // carries whatever tolerance the office wanted.
            'grace_minutes' => 0,
            'break_enabled' => $breakFeature,
            'break_start' => self::asClockTime($break['break_start']),
            'break_end' => self::asClockTime($break['break_end']),
            'shift' => null,
        ];
    }

    /**
     * Normalize a stored time to H:i. Shift columns come back as "08:00:00"
     * while the settings store holds "08:00"; callers should not have to care.
     */
    private static function asClockTime(string $value): string
    {
        return substr($value, 0, 5);
    }
}
