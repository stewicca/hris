<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use App\Support\AttendanceSettings;
use App\Support\WorkCalendar;
use Carbon\CarbonInterface;

class MarkDailyAbsentees
{
    /**
     * Mark active employees without attendance or approved leave on a working
     * day as absent. Returns the number of rows created, or -1 if the date is
     * not a working day.
     */
    public function handle(CarbonInterface $date): int
    {
        if (! WorkCalendar::isWorkingDay($date)) {
            return -1;
        }

        $onLeaveIds = Leave::query()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('employee_id');

        $accountedIds = Attendance::query()
            ->whereDate('date', $date)
            ->pluck('employee_id');

        $absentees = Employee::query()
            ->where('status', 'active')
            ->whereNotIn('id', $accountedIds)
            ->whereNotIn('id', $onLeaveIds)
            ->with('shift')
            ->get();

        foreach ($absentees as $employee) {
            $employee->attendances()->create([
                'date' => $date->toDateString(),
                'shift_id' => AttendanceSettings::resolveShift($employee, $date)?->id,
                'status' => 'absent',
            ]);
        }

        return $absentees->count();
    }
}
