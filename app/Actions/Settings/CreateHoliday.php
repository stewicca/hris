<?php

namespace App\Actions\Settings;

use App\Models\Holiday;
use App\Support\WorkCalendar;

class CreateHoliday
{
    /**
     * @param  array{date: string, name: string}  $validated
     */
    public function handle(array $validated): Holiday
    {
        $holiday = Holiday::create($validated);
        WorkCalendar::forgetHolidayCache();

        return $holiday;
    }
}
