<?php

namespace App\Actions\Settings;

use App\Models\Setting;

class UpdateWorkingDays
{
    /**
     * @param  list<int>  $workingDays
     */
    public function handle(array $workingDays): void
    {
        Setting::set('working_days', array_values(array_unique($workingDays)));
    }
}
