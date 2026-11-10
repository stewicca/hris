<?php

namespace App\Http\Controllers\Attendance;

use App\Actions\Attendance\BuildDailyAttendanceRoster;
use App\Actions\Attendance\DeleteManualAttendance;
use App\Actions\Attendance\RecordManualAttendance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StoreAttendanceRequest;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function index(Request $request, BuildDailyAttendanceRoster $buildRoster): Response
    {
        $date = $request->date('date') ?? today();
        $department = $request->string('department')->value() ?: null;
        $statusFilter = $request->string('status')->value() ?: null;

        $roster = $buildRoster->handle($date, $department);

        $records = $statusFilter
            ? $roster['records']->filter(fn (array $r) => $r['status'] === $statusFilter)->values()
            : $roster['records'];

        return Inertia::render('attendance/index', [
            'records' => $records,
            'departments' => Department::orderBy('name')->pluck('name'),
            'filters' => [
                'date' => $date->toDateString(),
                'department' => $department,
                'status' => $statusFilter,
            ],
            'isWorkingDay' => $roster['is_working_day'],
        ]);
    }

    public function store(StoreAttendanceRequest $request, RecordManualAttendance $record): RedirectResponse
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));

        $record->handle(
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

        return back()->with('success', "Kehadiran {$employee->name} berhasil dicatat.");
    }

    public function destroy(Attendance $attendance, DeleteManualAttendance $delete): RedirectResponse
    {
        $delete->handle($attendance);

        return back()->with('success', 'Catatan kehadiran dihapus.');
    }
}
