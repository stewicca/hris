<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Shift;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Prices attendance against the deduction ladders that applied to it.
 *
 * {@see PayrollDeductionSettings} holds the rules and knows what a given number
 * of minutes costs; this is the piece that decides how many minutes there were.
 * It reads the mirror columns on an attendance row, compares them against the
 * schedule that applied on that date, and reports what each rule group cost.
 *
 * Everything is graded against the shift the attendance row snapshotted, not
 * the one the employee is assigned today, so moving somebody onto a later shift
 * cannot retroactively forgive — or invent — last month's lateness.
 *
 * Two silences are deliberate. A day marked sick or excused costs nothing: that
 * is the whole point of recording it as such. And a day with no check-out is
 * not charged for leaving early, because a missing clock-out is evidence of a
 * forgotten tap, not of an early exit — an admin who knows otherwise can write
 * the real time in by hand and the charge follows.
 */
final class AttendanceDeduction
{
    /** How each group reads in a per-day breakdown, e.g. the attendance export. */
    private const array REASON_LABELS = [
        'late' => 'Terlambat',
        'early_leave' => 'Pulang cepat',
        'break_overrun' => 'Kelebihan istirahat',
        'absent' => 'Tidak hadir',
    ];

    /** How each group reads as a line on a payslip. */
    private const array COMPONENT_LABELS = [
        'late' => 'Potongan Keterlambatan',
        'early_leave' => 'Potongan Pulang Cepat',
        'break_overrun' => 'Potongan Kelebihan Istirahat',
        'absent' => 'Potongan Ketidakhadiran',
    ];

    /**
     * @param  array<string, array{minutes: int|null, amount: int}>  $lines  Keyed by
     *                                                                      rule group, in the order {@see PayrollDeductionSettings::GROUPS}
     *                                                                      declares. Groups that cost nothing are absent entirely.
     *                                                                      `minutes` is null for absence, which is counted in days.
     */
    private function __construct(
        public readonly array $lines,
        public readonly int $total,
    ) {}

    /**
     * What one day of attendance costs.
     */
    public static function for(Attendance $attendance): self
    {
        $shift = self::shiftFor($attendance);
        $rules = PayrollDeductionSettings::forShift($shift);

        // Nothing configured is the common case — a fresh installation and any
        // office that never opened the screen — so it is worth not walking the
        // clock at all.
        if (! PayrollDeductionSettings::anyEnabled($rules)) {
            return self::empty();
        }

        if (in_array($attendance->status, Attendance::EXCUSED_STATUSES, true)) {
            return self::empty();
        }

        if ($attendance->status === 'absent') {
            return self::build(['absent' => [null, PayrollDeductionSettings::absentDeduction($rules, 1)]]);
        }

        $schedule = AttendanceSettings::scheduleFromShift($shift);

        $late = self::lateMinutes($attendance, $schedule, $rules);
        $earlyLeave = self::earlyLeaveMinutes($attendance, $schedule);
        $breakOverrun = self::breakOverrunMinutes($attendance, $schedule);

        return self::build([
            'late' => [$late, PayrollDeductionSettings::lateDeduction($rules, $late)],
            'early_leave' => [$earlyLeave, PayrollDeductionSettings::earlyLeaveDeduction($rules, $earlyLeave)],
            'break_overrun' => [$breakOverrun, PayrollDeductionSettings::breakOverrunDeduction($rules, $breakOverrun)],
        ]);
    }

    /**
     * What a whole pay period costs, with each group's days rolled into one
     * line. Only the month and year of `$month` are read.
     */
    public static function forMonth(Employee $employee, CarbonInterface $month): self
    {
        $totals = [];

        $employee->attendances()
            ->whereYear('date', $month->year)
            ->whereMonth('date', $month->month)
            // The snapshot shift decides both the schedule and the ladders, so
            // loading it here keeps a full month off one query per day.
            ->with('shift')
            ->get()
            ->each(function (Attendance $attendance) use ($employee, &$totals): void {
                foreach (self::for($attendance->setRelation('employee', $employee))->lines as $group => $line) {
                    $totals[$group] ??= [null, 0];
                    $totals[$group][0] = $line['minutes'] === null
                        ? $totals[$group][0]
                        : (int) $totals[$group][0] + $line['minutes'];
                    $totals[$group][1] += $line['amount'];
                }
            });

        return self::build($totals);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * A human breakdown for a single row of a report, e.g.
     * "Terlambat 22 mnt: 15.000; Pulang cepat 30 mnt: 20.000". Empty when
     * nothing was deducted.
     */
    public function reason(): string
    {
        $parts = [];

        foreach ($this->lines as $group => $line) {
            $label = self::REASON_LABELS[$group];
            $minutes = $line['minutes'] === null ? '' : " {$line['minutes']} mnt";

            $parts[] = $label.$minutes.': '.number_format($line['amount'], 0, ',', '.');
        }

        return implode('; ', $parts);
    }

    /**
     * These deductions as payslip components, one line per rule group so the
     * slip says what was cut rather than only how much.
     *
     * @return list<array{label: string, amount: int, type: string}>
     */
    public function salaryComponents(): array
    {
        $components = [];

        foreach ($this->lines as $group => $line) {
            $components[] = [
                'label' => self::COMPONENT_LABELS[$group],
                'amount' => $line['amount'],
                'type' => 'deduction',
            ];
        }

        return $components;
    }

    private static function empty(): self
    {
        return new self([], 0);
    }

    /**
     * Assemble from `[group => [minutes, amount]]`, dropping every group that
     * cost nothing and holding the rest in the order the settings declare.
     *
     * @param  array<string, array{0: int|null, 1: int}>  $raw
     */
    private static function build(array $raw): self
    {
        $lines = [];
        $total = 0;

        foreach (PayrollDeductionSettings::GROUPS as $group) {
            [$minutes, $amount] = $raw[$group] ?? [null, 0];

            if ($amount <= 0) {
                continue;
            }

            $lines[$group] = ['minutes' => $minutes, 'amount' => $amount];
            $total += $amount;
        }

        return new self($lines, $total);
    }

    /**
     * The shift this record is graded against: the snapshot it was written
     * with, or — for rows predating the snapshot, and for absences the
     * scheduler recorded without one — whatever resolves for that date now.
     */
    private static function shiftFor(Attendance $attendance): ?Shift
    {
        if (! FeatureSettings::shiftEnabled()) {
            return null;
        }

        return $attendance->shift
            ?? AttendanceSettings::resolveShift($attendance->employee, $attendance->date);
    }

    /**
     * Minutes past the point at which lateness starts costing money: either the
     * scheduled check-in or, when the rules say so, the late threshold with its
     * grace period spent — the same moment {@see Attendance::resolveStatus()}
     * starts calling a day late.
     *
     * @param  array<string, mixed>  $schedule
     * @param  array<string, mixed>  $rules
     */
    private static function lateMinutes(Attendance $attendance, array $schedule, array $rules): int
    {
        if ($attendance->check_in === null) {
            return 0;
        }

        $reference = $rules['late']['basis'] === PayrollDeductionSettings::BASIS_LATE_THRESHOLD
            ? self::clock($schedule['late_threshold'])->addMinutes($schedule['grace_minutes'])
            : self::clock($schedule['check_in']);

        return max(0, (int) $reference->diffInMinutes(self::clock($attendance->check_in), false));
    }

    /**
     * Minutes between clocking out and the scheduled end of the day.
     *
     * @param  array<string, mixed>  $schedule
     */
    private static function earlyLeaveMinutes(Attendance $attendance, array $schedule): int
    {
        if ($attendance->check_in === null || $attendance->check_out === null) {
            return 0;
        }

        $scheduledOut = self::endOf($schedule['check_in'], $schedule['check_out']);
        $actualOut = self::endOf($attendance->check_in, $attendance->check_out);

        return max(0, (int) $actualOut->diffInMinutes($scheduledOut, false));
    }

    /**
     * Minutes the break ran beyond the length the schedule allots it.
     *
     * @param  array<string, mixed>  $schedule
     */
    private static function breakOverrunMinutes(Attendance $attendance, array $schedule): int
    {
        if (! $schedule['break_enabled']
            || $schedule['break_start'] === null
            || $schedule['break_end'] === null
            || $attendance->break_start === null
            || $attendance->break_end === null) {
            return 0;
        }

        $allotted = self::span($schedule['break_start'], $schedule['break_end']);
        $taken = self::span($attendance->break_start, $attendance->break_end);

        return max(0, $taken - $allotted);
    }

    /**
     * The end of a window as a point in time, rolled onto the next day when it
     * sorts before its own start — which is how a night shift's 06:00 stops
     * reading as sixteen hours before its 22:00.
     */
    private static function endOf(string $start, string $end): Carbon
    {
        $from = self::clock($start);
        $to = self::clock($end);

        return $to > $from ? $to : $to->addDay();
    }

    private static function span(string $start, string $end): int
    {
        return (int) self::clock($start)->diffInMinutes(self::endOf($start, $end));
    }

    /**
     * A clock time as a point on one shared arbitrary day, so two of them can
     * be compared. Accepts both "08:00" from the settings store and "08:00:00"
     * from a time column.
     */
    private static function clock(string $time): Carbon
    {
        return Carbon::createFromFormat('!H:i', substr($time, 0, 5));
    }
}
