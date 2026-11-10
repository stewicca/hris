<?php

namespace App\Actions\Settings;

use App\Models\Setting;

class UpdateFeatureSettings
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(array $validated): void
    {
        Setting::set('leave_enabled', (bool) $validated['leave_enabled']);
        Setting::set('attendance_break_enabled', (bool) $validated['attendance_break_enabled']);
        Setting::set('attendance_shift_enabled', (bool) $validated['attendance_shift_enabled']);
        Setting::set('payroll_enabled', (bool) $validated['payroll_enabled']);
        Setting::set('kiosk_enabled', (bool) $validated['kiosk_enabled']);
    }
}
