<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use App\Support\WorkCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class BuildDailyAttendanceRoster
{
    /**
     * Build the daily attendance roster and summary for a date.
     *
     * @return array{
     *     is_working_day: bool,
     *     records: Collection<int, array<string, mixed>>,
     *     summary: array{total: int, present: int, late: int, absent: int, excused: int, leave: int, is_working_day: bool}
     * }
     */
    public function handle(CarbonInterface $date, ?string $department = null): array
    {
        $isWorkingDay = WorkCalendar::isWorkingDay($date);

        $employees = Employee::query()
            ->where('status', 'active')
            ->with([
                'attendances' => fn ($q) => $q->whereDate('date', $date),
                'attendances.events:id,attendance_id,recorded_by',
                'department',
                'position',
            ])
            ->when($department, fn ($q) => $q->whereHas('department', fn ($d) => $d->where('name', $department)))
            ->orderBy('name')
            ->get();

        $onLeaveIds = Leave::query()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('employee_id')
            ->flip();

        $records = $employees->map(function (Employee $employee) use ($isWorkingDay, $onLeaveIds): array {
            $attendance = $employee->attendances->first();
            $status = Attendance::effectiveStatus($attendance, $isWorkingDay, $onLeaveIds->has($employee->id));

            return [
                'employee_id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'name' => $employee->name,
                'department' => $employee->department?->name,
                'position' => $employee->position?->name,
                'attendance_id' => $attendance?->id,
                'check_in' => $attendance?->check_in,
                'check_out' => $attendance?->check_out,
                'break_start' => $attendance?->break_start,
                'break_end' => $attendance?->break_end,
                'notes' => $attendance?->notes,
                'recorded_manually' => $attendance?->recorded_by !== null,
                'can_delete' => (bool) $attendance?->isFullyManual(),
                'status' => $status,
            ];
        });

        $summary = [
            'total' => $records->count(),
            'present' => $records->where('status', 'present')->count(),
            'late' => $records->where('status', 'late')->count(),
            'absent' => $records->where('status', 'absent')->count(),
            'excused' => $records->whereIn('status', Attendance::EXCUSED_STATUSES)->count(),
            'leave' => $records->where('status', 'leave')->count(),
            'is_working_day' => $isWorkingDay,
        ];

        return [
            'is_working_day' => $isWorkingDay,
            'records' => $records,
            'summary' => $summary,
        ];
    }
}
