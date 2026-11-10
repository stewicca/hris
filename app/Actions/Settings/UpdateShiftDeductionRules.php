<?php

namespace App\Actions\Settings;

use App\Models\Shift;
use App\Support\PayrollDeductionSettings;

class UpdateShiftDeductionRules
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(Shift $shift, array $validated): void
    {
        $shift->update([
            'deduction_rules' => $validated['overrides']
                ? PayrollDeductionSettings::normalize($validated)
                : null,
        ]);
    }
}
