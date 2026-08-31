<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\Setting;
use App\Models\Shift;
use Carbon\CarbonInterface;

/**
 * Runtime configuration for attendance-driven salary deductions.
 *
 * Three rule groups hold a ladder of tiers — arriving late, leaving early, and
 * overstaying a break. A tier is `{from_minutes, amount}`: once an employee is
 * at least `from_minutes` past the scheduled time, `amount` rupiah is cut for
 * that day. Only the highest tier reached applies, so a ladder of 15 → 15.000
 * and 30 → 40.000 cuts 15.000 at twenty minutes late and 40.000 at forty-five —
 * never both.
 *
 * The fourth group, absence, is a flat amount per day instead: there is no
 * "how late" to grade when nobody turned up.
 *
 * The minutes are measured against whatever schedule applied to the employee on
 * that date ({@see AttendanceSettings::scheduleFor()}), so a night shift is
 * graded against its own start time rather than the office's.
 *
 * Rules resolve in two levels, mirroring how schedules already resolve: a shift
 * may carry its own ladders in shifts.deduction_rules, and anyone whose shift
 * does not override them — or who has no shift at all — falls back to the
 * installation-wide set held in the {@see Setting} key-value store.
 */
class PayrollDeductionSettings
{
    public const string KEY = 'payroll_deductions';

    /** Count lateness from the scheduled check-in time. */
    public const string BASIS_CHECK_IN = 'check_in';

    /** Count lateness from the late threshold, i.e. once tolerance has run out. */
    public const string BASIS_LATE_THRESHOLD = 'late_threshold';

    /** Groups graded by a minute ladder. */
    public const array TIERED_GROUPS = ['late', 'early_leave', 'break_overrun'];

    /** Every group, in the order they are presented to the admin. */
    public const array GROUPS = ['late', 'early_leave', 'break_overrun', 'absent'];

    /**
     * A ladder deep enough for any tolerance policy an office actually writes
     * down, and shallow enough that the form stays readable.
     */
    public const int MAX_TIERS = 10;

    /** A tier cannot start beyond a full day of lateness. */
    public const int MAX_FROM_MINUTES = 1440;

    /** Guards against a fat-fingered extra zero turning one late arrival into a year's pay. */
    public const int MAX_AMOUNT = 100_000_000;

    /**
     * Everything off, with no tiers. An installation that never opens this
     * screen must never see a rupiah deducted.
     *
     * @var array<string, array<string, mixed>>
     */
    public const array DEFAULTS = [
        'late' => [
            'enabled' => false,
            'basis' => self::BASIS_CHECK_IN,
            'tiers' => [],
        ],
        'early_leave' => [
            'enabled' => false,
            'tiers' => [],
        ],
        'break_overrun' => [
            'enabled' => false,
            'tiers' => [],
        ],
        'absent' => [
            'enabled' => false,
            'amount' => 0,
        ],
    ];

    /**
     * The installation-wide rule set: the fallback for every employee whose
     * shift does not override it.
     *
     * @return array{
     *     late: array{enabled: bool, basis: string, tiers: list<array{from_minutes: int, amount: int}>},
     *     early_leave: array{enabled: bool, tiers: list<array{from_minutes: int, amount: int}>},
     *     break_overrun: array{enabled: bool, tiers: list<array{from_minutes: int, amount: int}>},
     *     absent: array{enabled: bool, amount: int},
     * }
     */
    public static function globalRules(): array
    {
        $stored = Setting::get(self::KEY, []);

        return self::normalize(is_array($stored) ? $stored : []);
    }

    /**
     * Persist the installation-wide rule set. Input is normalized first, so
     * what comes back out of {@see globalRules()} is already sorted,
     * integer-typed and free of stray keys.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function save(array $settings): void
    {
        Setting::set(self::KEY, self::normalize($settings));
    }

    /**
     * The rule set that applies to an employee on a date: their shift's own
     * ladders when it overrides, otherwise the installation-wide set.
     *
     * Shift mode being switched off collapses this to the global set, because
     * {@see AttendanceSettings::resolveShift()} stops resolving a shift at all.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forEmployee(Employee $employee, CarbonInterface $date): array
    {
        return self::forShift(AttendanceSettings::resolveShift($employee, $date));
    }

    /**
     * The rule set that applies to an already-resolved shift, or the
     * installation-wide set when there is none.
     *
     * Attendance rows snapshot the shift that applied on the day, so pricing a
     * past record starts from that snapshot rather than from a fresh resolve —
     * reassigning somebody's shift must not silently reprice last month.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forShift(?Shift $shift): array
    {
        return $shift?->deductionRules() ?? self::globalRules();
    }

    /**
     * Rupiah to deduct for arriving `$minutesLate` past the configured basis.
     *
     * @param  array<string, mixed>  $rules
     */
    public static function lateDeduction(array $rules, int $minutesLate): int
    {
        return self::amountFor($rules['late'], $minutesLate);
    }

    /**
     * Rupiah to deduct for leaving `$minutesEarly` before the scheduled
     * check-out.
     *
     * @param  array<string, mixed>  $rules
     */
    public static function earlyLeaveDeduction(array $rules, int $minutesEarly): int
    {
        return self::amountFor($rules['early_leave'], $minutesEarly);
    }

    /**
     * Rupiah to deduct for a break that ran `$minutesOver` longer than the
     * schedule allots. It is the length that is graded, not the clock: an
     * employee who starts late and still takes their hour has overrun nothing.
     *
     * @param  array<string, mixed>  $rules
     */
    public static function breakOverrunDeduction(array $rules, int $minutesOver): int
    {
        return self::amountFor($rules['break_overrun'], $minutesOver);
    }

    /**
     * Rupiah to deduct for `$days` absent without leave.
     *
     * Approved leave never reaches this: attendance:mark-absentees excludes
     * employees with an approved leave covering the date, so an `absent` row
     * means nobody accounted for the day at all.
     *
     * @param  array<string, mixed>  $rules
     */
    public static function absentDeduction(array $rules, int $days): int
    {
        if (! $rules['absent']['enabled'] || $days <= 0) {
            return 0;
        }

        return $rules['absent']['amount'] * $days;
    }

    /**
     * Whether any group in a rule set is switched on. Lets a payslip draft skip
     * walking the attendance history when no rule could possibly fire.
     *
     * @param  array<string, mixed>  $rules
     */
    public static function anyEnabled(array $rules): bool
    {
        foreach (self::GROUPS as $group) {
            if ($rules[$group]['enabled']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Coerce any rule set into its canonical shape. Public because a shift's
     * override is stored on the shift itself and has to pass through the same
     * normalization as the global set.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, array<string, mixed>>
     */
    public static function normalize(array $settings): array
    {
        $normalized = [];

        foreach (self::GROUPS as $group) {
            $normalized[$group] = self::normalizeGroup(
                $group,
                is_array($settings[$group] ?? null) ? $settings[$group] : [],
            );
        }

        return $normalized;
    }

    /**
     * Resolve a minute count against one group's ladder: the highest tier the
     * employee actually reached wins, and nothing below the first tier is
     * deducted at all.
     *
     * @param  array{enabled: bool, tiers: list<array{from_minutes: int, amount: int}>}  $group
     */
    private static function amountFor(array $group, int $minutes): int
    {
        if (! $group['enabled'] || $minutes <= 0) {
            return 0;
        }

        $amount = 0;

        // Tiers are normalized ascending, so the last one that fits is the
        // deepest one reached.
        foreach ($group['tiers'] as $tier) {
            if ($minutes >= $tier['from_minutes']) {
                $amount = $tier['amount'];
            }
        }

        return $amount;
    }

    /**
     * Coerce one group into its canonical shape: the keys its defaults declare
     * and nothing else, integers as integers, tiers sorted by threshold.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function normalizeGroup(string $group, array $values): array
    {
        $defaults = self::DEFAULTS[$group];

        $normalized = ['enabled' => (bool) ($values['enabled'] ?? $defaults['enabled'])];

        if (array_key_exists('basis', $defaults)) {
            $basis = $values['basis'] ?? $defaults['basis'];

            $normalized['basis'] = in_array($basis, [self::BASIS_CHECK_IN, self::BASIS_LATE_THRESHOLD], true)
                ? $basis
                : $defaults['basis'];
        }

        if (array_key_exists('amount', $defaults)) {
            $normalized['amount'] = max(0, (int) ($values['amount'] ?? $defaults['amount']));
        }

        if (array_key_exists('tiers', $defaults)) {
            $normalized['tiers'] = self::normalizeTiers(
                is_array($values['tiers'] ?? null) ? $values['tiers'] : [],
            );
        }

        return $normalized;
    }

    /**
     * @param  array<int|string, mixed>  $tiers
     * @return list<array{from_minutes: int, amount: int}>
     */
    private static function normalizeTiers(array $tiers): array
    {
        return collect($tiers)
            ->filter(fn ($tier): bool => is_array($tier)
                && isset($tier['from_minutes'])
                && isset($tier['amount']))
            ->map(fn (array $tier): array => [
                'from_minutes' => max(0, (int) $tier['from_minutes']),
                'amount' => max(0, (int) $tier['amount']),
            ])
            // A duplicated threshold is unreachable — the later rung would
            // always shadow the earlier. Validation rejects it up front; this
            // keeps a hand-edited settings row from growing dead rungs.
            ->uniqueStrict('from_minutes')
            ->sortBy('from_minutes')
            ->take(self::MAX_TIERS)
            ->values()
            ->all();
    }
}
