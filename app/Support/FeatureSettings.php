<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Centralizes feature toggles so admins can enable or disable whole modules
 * at runtime without touching code or environment variables.
 *
 * Values are persisted in the {@see Setting} key-value store and cached there.
 */
class FeatureSettings
{
    public const DEFAULT_LEAVE_ENABLED = true;

    public const DEFAULT_ATTENDANCE_BREAK_ENABLED = false;

    public const DEFAULT_ATTENDANCE_SHIFT_ENABLED = false;

    public const DEFAULT_PAYROLL_ENABLED = true;

    /**
     * Whether the leave (cuti) module is enabled.
     */
    public static function leaveEnabled(): bool
    {
        return (bool) Setting::get('leave_enabled', self::DEFAULT_LEAVE_ENABLED);
    }

    /**
     * Whether the payroll (penggajian / slip gaji) module is enabled.
     */
    public static function payrollEnabled(): bool
    {
        return (bool) Setting::get('payroll_enabled', self::DEFAULT_PAYROLL_ENABLED);
    }

    /**
     * Whether break (istirahat) tracking is enabled for attendance.
     */
    public static function breakEnabled(): bool
    {
        return (bool) Setting::get('attendance_break_enabled', self::DEFAULT_ATTENDANCE_BREAK_ENABLED);
    }

    /**
     * Whether per-employee shift assignments are enabled for attendance.
     */
    public static function shiftEnabled(): bool
    {
        return (bool) Setting::get('attendance_shift_enabled', self::DEFAULT_ATTENDANCE_SHIFT_ENABLED);
    }
}
