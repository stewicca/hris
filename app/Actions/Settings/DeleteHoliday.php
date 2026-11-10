<?php

namespace App\Actions\Settings;

use App\Models\Holiday;
use App\Support\WorkCalendar;

class DeleteHoliday
{
    public function handle(Holiday $holiday): void
    {
        $holiday->delete();
        WorkCalendar::forgetHolidayCache();
    }
}
