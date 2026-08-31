<?php

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\StoreEmployeeRequest;
use App\Http\Requests\Employees\UpdateEmployeeRequest;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Shift;
use App\Models\User;
use App\Support\AttendanceDeduction;
use App\Support\UsernameGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    /**
     * How many times a create is retried when a generated value collides.
     */
    private const int CREATE_ATTEMPTS = 3;

    public function index(): Response
    {
        $employees = Employee::query()
            ->with(['user', 'department', 'position'])
            ->latest()
            ->paginate(15);

        return Inertia::render('employees/index', [
            'employees' => $employees,
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

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $plainPassword = Str::password(12, symbols: false);

        $this->createEmployeeWithAccount($request, $plainPassword);

        return to_route('employees.index')
            ->with('generated_password', $plainPassword);
    }

    /**
     * Write the employee and the login account it belongs to in one
     * transaction, retrying when a generated value loses a race.
     *
     * Both rows go in together because a failure after the user row exists
     * would otherwise strand a login with no employee behind it.
     *
     * The username and the employee number are each derived from a read that
     * cannot see another request's uncommitted rows, so two creates running
     * at the same moment can derive the same value and collide on a unique
     * index. The row lock on the employee number covers the common case;
     * retrying covers what a lock cannot — a username derived from an
     * identical name, and the very first employee, where there is no row to
     * lock yet. Each retry re-derives both against what the winner has since
     * committed.
     *
     * A collision retrying cannot resolve — a duplicate email that slipped
     * past validation, say — exhausts the attempts and rethrows.
     */
    private function createEmployeeWithAccount(StoreEmployeeRequest $request, string $plainPassword): void
    {
        foreach (range(1, self::CREATE_ATTEMPTS) as $attempt) {
            try {
                DB::transaction(function () use ($request, $plainPassword): void {
                    $user = User::create([
                        'name' => $request->name,
                        'username' => UsernameGenerator::generate($request->name),
                        'email' => $request->email,
                        'password' => Hash::make($plainPassword),
                        'is_admin' => $request->boolean('is_admin'),
                    ]);

                    Employee::create([
                        ...$request->validated(),
                        'user_id' => $user->id,
                        'employee_number' => Employee::generateEmployeeNumber(),
                        'annual_leave_quota' => $request->filled('annual_leave_quota') ? $request->integer('annual_leave_quota') : 12,
                    ]);
                });

                return;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === self::CREATE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
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
            // Each row is priced against the shift it snapshotted, so loading
            // them here keeps a year of recap off one query per day.
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

    public function attendanceExport(Employee $employee): StreamedResponse
    {
        $attendances = $employee->attendances()->with('shift')->orderBy('date', 'desc')->get();

        $filename = 'kehadiran-'.Str::slug($employee->name).'-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($attendances, $employee) {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['Nama', 'No. Karyawan', 'Tanggal', 'Masuk', 'Pulang', 'Istirahat Mulai', 'Istirahat Selesai', 'Status', 'Potongan', 'Rincian Potongan']);

            foreach ($attendances as $a) {
                $deduction = AttendanceDeduction::for($a->setRelation('employee', $employee));

                fputcsv($handle, [
                    $employee->name,
                    $employee->employee_number,
                    $a->date->format('d/m/Y'),
                    $a->check_in ?? '-',
                    $a->check_out ?? '-',
                    $a->break_start ?? '-',
                    $a->break_end ?? '-',
                    match ($a->status) {
                        'present' => 'Hadir',
                        'late' => 'Terlambat',
                        'absent' => 'Absen',
                        'sick' => 'Sakit',
                        'permit' => 'Izin',
                        default => $a->status,
                    },
                    // A bare number rather than the '-' the time columns use,
                    // so the column still sums in a spreadsheet.
                    $deduction->total,
                    $deduction->reason() ?: '-',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
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

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $validated = $request->validated();
        $validated['annual_leave_quota'] = $request->filled('annual_leave_quota')
            ? $request->integer('annual_leave_quota')
            : $employee->annual_leave_quota;

        $employee->fill($validated);

        if ($employee->isDirty('email') && $employee->user) {
            $employee->user->update(['email' => $request->email]);
        }

        if ($employee->isDirty('name') && $employee->user) {
            $employee->user->update(['name' => $request->name]);
        }

        if ($employee->user) {
            $employee->user->update(['is_admin' => $request->boolean('is_admin')]);
        }

        $employee->save();

        return to_route('employees.index');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $employee->delete();

        return to_route('employees.index');
    }

    public function resetPassword(Employee $employee): RedirectResponse
    {
        $plainPassword = Str::password(12, symbols: false);

        $employee->user->update([
            'password' => Hash::make($plainPassword),
        ]);

        return back()->with('generated_password', $plainPassword);
    }
}
