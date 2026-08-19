<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Leave;
use App\Support\WorkCalendar;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function index(Request $request): Response
    {
        $date = $request->date('date') ?? today();
        $department = $request->string('department')->value() ?: null;
        $statusFilter = $request->string('status')->value() ?: null;
        $isWorkingDay = WorkCalendar::isWorkingDay($date);

        $employees = Employee::query()
            ->where('status', 'active')
            ->with(['attendances' => fn ($q) => $q->whereDate('date', $date), 'department', 'position'])
            ->when($department, fn ($q) => $q->whereHas('department', fn ($d) => $d->where('name', $department)))
            ->orderBy('name')
            ->get();

        $onLeaveIds = Leave::query()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('employee_id')
            ->flip();

        $allRecords = $employees->map(function ($employee) use ($isWorkingDay, $onLeaveIds) {
            $attendance = $employee->attendances->first();

            $status = $attendance?->status ?? match (true) {
                ! $isWorkingDay => 'holiday',
                $onLeaveIds->has($employee->id) => 'leave',
                default => 'absent',
            };

            return [
                'employee_id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'name' => $employee->name,
                'department' => $employee->department?->name,
                'position' => $employee->position?->name,
                'check_in' => $attendance?->check_in,
                'check_out' => $attendance?->check_out,
                'break_start' => $attendance?->break_start,
                'break_end' => $attendance?->break_end,
                'status' => $status,
            ];
        });

        $records = $statusFilter
            ? $allRecords->filter(fn ($r) => $r['status'] === $statusFilter)->values()
            : $allRecords;

        $departments = Department::orderBy('name')->pluck('name');

        return Inertia::render('attendance/index', [
            'records' => $records,
            'departments' => $departments,
            'filters' => [
                'date' => $date->toDateString(),
                'department' => $department,
                'status' => $statusFilter,
            ],
            'isWorkingDay' => $isWorkingDay,
        ]);
    }
}
