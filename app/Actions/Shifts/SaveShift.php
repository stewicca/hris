<?php

namespace App\Actions\Shifts;

use App\Models\Shift;
use Illuminate\Http\Request;

class SaveShift
{
    public function handle(Request $request, ?Shift $shift = null): Shift
    {
        $validated = $this->validateShift($request, $shift?->id);

        if ($shift) {
            $shift->update($validated);

            return $shift;
        }

        return Shift::create($validated);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateShift(Request $request, ?int $ignoreId = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100', 'unique:shifts,name'.($ignoreId ? ",{$ignoreId}" : '')],
            'check_in' => ['required', 'date_format:H:i'],
            'check_out' => ['required', 'date_format:H:i', 'different:check_in'],
            'late_threshold' => ['required', 'date_format:H:i'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:480'],
            'break_enabled' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];

        if ($request->boolean('break_enabled')) {
            $rules['break_start'] = ['required', 'date_format:H:i'];
            $rules['break_end'] = ['required', 'date_format:H:i', 'different:break_start'];
        }

        return $request->validate($rules);
    }
}
