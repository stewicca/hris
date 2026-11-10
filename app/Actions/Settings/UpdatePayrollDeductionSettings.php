<?php

namespace App\Actions\Settings;

use App\Support\PayrollDeductionSettings;

class UpdatePayrollDeductionSettings
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(array $validated): void
    {
        PayrollDeductionSettings::save($validated);
    }
}
