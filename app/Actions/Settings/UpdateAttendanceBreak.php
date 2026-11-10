<?php

namespace App\Actions\Settings;

use App\Models\Setting;

class UpdateAttendanceBreak
{
    /**
     * @param  array{break_start: string, break_end: string}  $validated
     */
    public function handle(array $validated): void
    {
        Setting::set('break_window', $validated);
    }
}
