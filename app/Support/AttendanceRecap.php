<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * One row per employee summarising a span of attendance, with what it cost.
 *
 * This is the roster-wide counterpart to the per-employee export on the
 * employee page: that one answers "which day was he late?", this one answers
 * "what do I cut from each person this month?" — a shape that goes straight
 * into a payroll sheet.
 *
 * Everything is loaded up front and priced in memory. A recap over fifty
 * employees is four queries, not four hundred.
 */
final class AttendanceRecap
{
    /**
     * The header of the exported sheet, in the order {@see rows()} lays each
     * row out.
     *
     * @var list<string>
     */
    public const array COLUMNS = [
        'Nama',
        'No. Karyawan',
        'Departemen',
        'Jabatan',
        'Hari Kerja',
        'Hadir',
        'Terlambat',
        'Absen',
        'Izin/Sakit',
        'Cuti',
        'Potongan Terlambat',
        'Potongan Pulang Cepat',
        'Potongan Istirahat',
        'Potongan Absen',
        'Total Potongan',
    ];

    /**
     * Both ends of the span are inclusive. `$department` filters by name, the
     * way the attendance board already does.
     *
     * @return Collection<int, array{name: string, employee_number: string, department: string|null, position: string|null, working_days: int, present: int, late: int, absent: int, excused: int, leave: int, late_amount: int, early_leave_amount: int, break_overrun_amount: int, absent_amount: int, total_deduction: int}>
     */
    public static function rows(CarbonInterface $start, CarbonInterface $end, ?string $department = null): Collection
    {
        $employees = self::employeesIn($start, $end, $department);
        $workingDays = self::workingDays($start, $end);
        $leaveDays = self::approvedLeaveDays($employees, $start, $end);

        $attendances = Attendance::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereBetween('date', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->with('shift')
            ->get()
            ->groupBy('employee_id');

        return $employees->map(function (Employee $employee) use ($attendances, $leaveDays, $workingDays): array {
            /** @var Collection<int, Attendance> $records */
            $records = $attendances->get($employee->id) ?? collect();
            $deduction = AttendanceDeduction::forRecords($employee, $records);

            return [
                'name' => $employee->name,
                'employee_number' => $employee->employee_number,
                'department' => $employee->department?->name,
                'position' => $employee->position?->name,
                'working_days' => $workingDays,
                'present' => $records->where('status', 'present')->count(),
                'late' => $records->where('status', 'late')->count(),
                'absent' => $records->where('status', 'absent')->count(),
                'excused' => $records->whereIn('status', Attendance::EXCUSED_STATUSES)->count(),
                'leave' => $leaveDays[$employee->id] ?? 0,
                'late_amount' => $deduction->amountFor('late'),
                'early_leave_amount' => $deduction->amountFor('early_leave'),
                'break_overrun_amount' => $deduction->amountFor('break_overrun'),
                'absent_amount' => $deduction->amountFor('absent'),
                'total_deduction' => $deduction->total,
            ];
        })->values();
    }

    /**
     * Who belongs on the sheet: everyone on the payroll now, plus anyone who
     * has attendance inside the span. Somebody who resigned on the 15th still
     * has half a month to be paid for, and dropping them would quietly
     * shortchange them.
     *
     * @return Collection<int, Employee>
     */
    private static function employeesIn(CarbonInterface $start, CarbonInterface $end, ?string $department): Collection
    {
        return Employee::query()
            ->with(['department', 'position'])
            ->when($department, fn ($q) => $q->whereHas('department', fn ($d) => $d->where('name', $department)))
            ->where(fn ($q) => $q
                ->where('status', 'active')
                ->orWhereHas('attendances', fn ($a) => $a->whereBetween(
                    'date',
                    [$start->copy()->startOfDay(), $end->copy()->endOfDay()],
                )))
            ->orderBy('name')
            ->get();
    }

    /**
     * Working days in the span, per the work calendar — the denominator the
     * other counts are read against. It is the same for everybody, but it
     * travels on each row so the sheet explains itself without a legend.
     */
    private static function workingDays(CarbonInterface $start, CarbonInterface $end): int
    {
        return collect(CarbonPeriod::create($start->copy()->startOfDay(), $end->copy()->startOfDay()))
            ->filter(fn (CarbonInterface $date) => WorkCalendar::isWorkingDay($date))
            ->count();
    }

    /**
     * Working days each employee spent on approved leave inside the span.
     *
     * Leave never collides with an attendance row — attendance:mark-absentees
     * skips anyone whose leave covers the date — so this column is what keeps
     * the counts adding up to the working days beside them.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, int>
     */
    private static function approvedLeaveDays(Collection $employees, CarbonInterface $start, CarbonInterface $end): array
    {
        /** @var array<int, array<string, true>> $dates */
        $dates = [];

        Leave::query()
            ->where('status', 'approved')
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get()
            ->each(function (Leave $leave) use (&$dates, $start, $end): void {
                $from = $leave->start_date->max($start);
                $to = $leave->end_date->min($end);

                foreach (CarbonPeriod::create($from, $to) as $date) {
                    if (WorkCalendar::isWorkingDay($date)) {
                        // Keyed by date rather than counted, so two approved
                        // leaves that overlap cannot bill the same day twice.
                        $dates[$leave->employee_id][$date->toDateString()] = true;
                    }
                }
            });

        return array_map(count(...), $dates);
    }
}
