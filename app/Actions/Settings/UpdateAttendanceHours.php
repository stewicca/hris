<?php

namespace App\Actions\Settings;

use App\Models\Setting;

class UpdateAttendanceHours
{
    /**
     * @param  array{check_in: string, check_out: string, late_threshold: string}  $validated
     */
    public function handle(array $validated): void
    {
        Setting::set('office_hours', $validated);
    }
}
