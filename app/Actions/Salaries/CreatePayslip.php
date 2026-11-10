<?php

namespace App\Actions\Salaries;

use App\Models\Employee;
use App\Models\Salary;
use App\Notifications\EmployeeNotification;
use App\Support\AttendanceDeduction;
use Illuminate\Support\Carbon;

class CreatePayslip
{
    /**
     * @param  list<array{label: string, amount: int, type: string}>  $components
     */
    public function handle(Employee $employee, string $periodString, array $components): Salary
    {
        $period = Carbon::createFromFormat('!Y-m', $periodString);

        $salary = $employee->salaries()->create([
            'period' => $period,
            'components' => [
                ...$components,
                ...AttendanceDeduction::forMonth($employee, $period)->salaryComponents(),
            ],
        ]);

        $employee->user?->notify(new EmployeeNotification(
            'Slip Gaji Terbit',
            "Slip gaji periode {$salary->period_label} sudah tersedia.",
            'salary',
        ));

        return $salary;
    }
}
