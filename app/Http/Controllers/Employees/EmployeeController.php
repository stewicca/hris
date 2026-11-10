<?php

namespace App\Http\Controllers\Employees;

use App\Actions\Attendance\ExportAttendanceRecapCsv;
use App\Actions\Attendance\ExportEmployeeAttendanceCsv;
use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\DeleteEmployee;
use App\Actions\Employees\ResetEmployeePassword;
use App\Actions\Employees\UpdateEmployee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\AttendanceRecapRequest;
use App\Http\Requests\Employees\StoreEmployeeRequest;
use App\Http\Requests\Employees\UpdateEmployeeRequest;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Shift;
use App\Support\AttendanceDeduction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    public function index(): Response
    {
        $employees = Employee::query()
            ->with(['user', 'department', 'position'])
            ->latest()
            ->paginate(15);

        return Inertia::render('employees/index', [
            'employees' => $employees,
            'departments' => Department::orderBy('name')->pluck('name'),
            'generatedPassword' => session('generated_password'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('employees/create', [
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'positions' => Position::orderBy('name')->get(['id', 'name']),
            'shifts' => Shift::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreEmployeeRequest $request, CreateEmployee $createEmployee): RedirectResponse
    {
        $plainPassword = $createEmployee->handle(
            validated: $request->validated(),
            isAdmin: $request->boolean('is_admin'),
            annualLeaveQuota: $request->filled('annual_leave_quota') ? $request->integer('annual_leave_quota') : null,
        );

        return to_route('employees.index')
            ->with('generated_password', $plainPassword);
    }

    public function show(Employee $employee): Response
    {
        $employee->load(['user', 'department', 'position']);

        $summary = $employee->attendances()
            ->whereYear('date', now()->year)
            ->whereMonth('date', now()->month)
            ->selectRaw("
                COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
                COUNT(CASE WHEN status = 'late' THEN 1 END) as late,
                COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent,
                COUNT(CASE WHEN status IN ('sick', 'permit') THEN 1 END) as excused,
                COUNT(*) as total
            ")
            ->first();

        $monthlyRecap = $employee->attendances()
            ->where('date', '>=', now()->startOfMonth()->subMonths(11))
            ->with('shift')
            ->orderBy('date', 'desc')
            ->get()
            ->groupBy(fn ($a) => $a->date->format('Y-m'))
            ->map(fn ($group) => [
                'year' => (int) $group->first()->date->format('Y'),
                'month' => (int) $group->first()->date->format('m'),
                'present' => $group->where('status', 'present')->count(),
                'late' => $group->where('status', 'late')->count(),
                'absent' => $group->where('status', 'absent')->count(),
                'excused' => $group->whereIn('status', Attendance::EXCUSED_STATUSES)->count(),
                'deduction' => $group->sum(
                    fn (Attendance $a) => AttendanceDeduction::for($a->setRelation('employee', $employee))->total,
                ),
                'total' => $group->count(),
            ])
            ->values();

        return Inertia::render('employees/show', [
            'employee' => $employee,
            'leaveSummary' => $employee->annualLeaveSummary(),
            'salaries' => $employee->salaries()->orderByDesc('period')->get(),
            'attendanceSummary' => [
                'present' => (int) $summary?->present,
                'late' => (int) $summary?->late,
                'absent' => (int) $summary?->absent,
                'excused' => (int) $summary?->excused,
                'total' => (int) $summary?->total,
            ],
            'monthlyRecap' => $monthlyRecap,
            'attendances' => Inertia::defer(
                fn () => $employee->attendances()
                    ->latest('date')
                    ->paginate(20),
            ),
        ]);
    }

    public function attendanceExport(Employee $employee, ExportEmployeeAttendanceCsv $export): StreamedResponse
    {
        return $export->handle($employee);
    }

    public function attendanceRecapExport(AttendanceRecapRequest $request, ExportAttendanceRecapCsv $export): StreamedResponse
    {
        return $export->handle(
            start: $request->date('start')->startOfDay(),
            end: $request->date('end')->startOfDay(),
            department: $request->input('department'),
        );
    }

    public function edit(Employee $employee): Response
    {
        return Inertia::render('employees/edit', [
            'employee' => $employee->load('user'),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'positions' => Position::orderBy('name')->get(['id', 'name']),
            'shifts' => Shift::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, UpdateEmployee $updateEmployee): RedirectResponse
    {
        $updateEmployee->handle(
            employee: $employee,
            validated: $request->validated(),
            isAdmin: $request->boolean('is_admin'),
            annualLeaveQuota: $request->filled('annual_leave_quota') ? $request->integer('annual_leave_quota') : null,
        );

        return to_route('employees.index');
    }

    public function destroy(Request $request, Employee $employee, DeleteEmployee $deleteEmployee): RedirectResponse
    {
        $deleteEmployee->handle($employee, $request->user());

        return to_route('employees.index');
    }

    public function resetPassword(Employee $employee, ResetEmployeePassword $resetPassword): RedirectResponse
    {
        $plainPassword = $resetPassword->handle($employee);

        return back()->with('generated_password', $plainPassword);
    }
}
