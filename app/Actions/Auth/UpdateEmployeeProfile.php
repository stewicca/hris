<?php

namespace App\Actions\Auth;

use App\Models\Employee;

class UpdateEmployeeProfile
{
    /**
     * @param  array{phone?: string|null}  $validated
     */
    public function handle(Employee $employee, array $validated): Employee
    {
        $employee->update([
            'phone' => $validated['phone'] ?? null,
        ]);

        return $employee;
    }
}
