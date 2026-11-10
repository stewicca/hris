<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

class UpdateEmployee
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(
        Employee $employee,
        array $validated,
        bool $isAdmin,
        ?int $annualLeaveQuota = null,
    ): Employee {
        $validated['annual_leave_quota'] = $annualLeaveQuota ?? $employee->annual_leave_quota;

        return DB::transaction(function () use ($employee, $validated, $isAdmin): Employee {
            $employee->fill($validated);

            if ($employee->user) {
                $userUpdates = ['is_admin' => $isAdmin];

                if ($employee->isDirty('email')) {
                    $userUpdates['email'] = $validated['email'];
                }

                if ($employee->isDirty('name')) {
                    $userUpdates['name'] = $validated['name'];
                }

                $employee->user->update($userUpdates);
            }

            $employee->save();

            return $employee;
        });
    }
}
