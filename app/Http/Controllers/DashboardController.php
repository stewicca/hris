<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use App\Support\WorkCalendar;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $today = today();
        $isWorkingDay = WorkCalendar::isWorkingDay($today);

        $employees = Employee::query()
            ->where('status', 'active')
            ->with(['attendances' => fn ($q) => $q->whereDate('date', $today)])
            ->get();

        $onLeaveIds = Leave::query()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->pluck('employee_id')
            ->flip();

        $statuses = $employees->map(function ($employee) use ($isWorkingDay, $onLeaveIds) {
            if ($status = $employee->attendances->first()?->status) {
                return $status;
            }

            return match (true) {
                ! $isWorkingDay => 'holiday',
                $onLeaveIds->has($employee->id) => 'leave',
                default => 'absent',
            };
        });

        $summary = [
            'total' => $employees->count(),
            'present' => $statuses->filter(fn ($s) => $s === 'present')->count(),
            'late' => $statuses->filter(fn ($s) => $s === 'late')->count(),
            'absent' => $statuses->filter(fn ($s) => $s === 'absent')->count(),
            'excused' => $statuses->filter(fn ($s) => in_array($s, Attendance::EXCUSED_STATUSES, true))->count(),
            'leave' => $statuses->filter(fn ($s) => $s === 'leave')->count(),
            'is_working_day' => $isWorkingDay,
        ];

        $leaveStats = [
            'pending' => Leave::query()->where('status', 'pending')->count(),
            'on_leave_today' => Leave::query()
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->count(),
            'approved_this_month' => Leave::query()
                ->where('status', 'approved')
                ->whereMonth('start_date', $today->month)
                ->whereYear('start_date', $today->year)
                ->count(),
        ];

        return Inertia::render('dashboard', [
            'summary' => $summary,
            'date' => $today->toDateString(),
            'leaveStats' => $leaveStats,
        ]);
    }
}
