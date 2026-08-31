<?php

namespace App\Http\Controllers\Attendance;

use App\Actions\RecordManualAttendance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StoreAttendanceRequest;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Leave;
use App\Notifications\EmployeeNotification;
use App\Support\WorkCalendar;
use Illuminate\Http\RedirectResponse;
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
            ->with([
                'attendances' => fn ($q) => $q->whereDate('date', $date),
                // Loaded so each row can say whether its record is safe to
                // delete without asking the database once per employee.
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

    /**
     * Record or correct one employee's day by hand.
     *
     * This is the only route to an attendance record that does not start with
     * the employee: it covers the person who forgot to clock in and says so
     * afterwards, and the person who is marked sick or excused — which, with
     * the leave module switched off, cannot be recorded any other way.
     */
    public function store(StoreAttendanceRequest $request, RecordManualAttendance $record): RedirectResponse
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));

        $attendance = $record->handle(
            employee: $employee,
            date: $request->date('date'),
            status: $request->string('status')->value(),
            times: [
                'check_in' => $request->input('check_in'),
                'check_out' => $request->input('check_out'),
                'break_start' => $request->input('break_start'),
                'break_end' => $request->input('break_end'),
            ],
            notes: $request->input('notes'),
            recordedBy: $request->user(),
        );

        $employee->user?->notify(new EmployeeNotification(
            'Kehadiran Dicatat Admin',
            sprintf(
                'Kehadiran Anda tanggal %s dicatat sebagai "%s" oleh admin.',
                $attendance->date->format('d M Y'),
                $this->statusLabel($attendance->status),
            ),
            'info',
        ));

        return back()->with('success', "Kehadiran {$employee->name} berhasil dicatat.");
    }

    /**
     * Remove a record that exists only because an admin created it. A day the
     * employee actually clocked keeps its audit trail; correct it instead.
     */
    public function destroy(Attendance $attendance): RedirectResponse
    {
        abort_unless(
            $attendance->isFullyManual(),
            403,
            'Hanya catatan yang dibuat manual oleh admin yang dapat dihapus.',
        );

        $attendance->delete();

        return back()->with('success', 'Catatan kehadiran dihapus.');
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'present' => 'Hadir',
            'late' => 'Terlambat',
            'sick' => 'Sakit',
            'permit' => 'Izin',
            default => 'Tidak Hadir',
        };
    }
}
