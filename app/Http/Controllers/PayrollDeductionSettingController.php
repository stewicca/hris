<?php

namespace App\Http\Controllers;

use App\Http\Requests\Salaries\UpdateDeductionRulesRequest;
use App\Http\Requests\Salaries\UpdateShiftDeductionRulesRequest;
use App\Models\Employee;
use App\Models\Shift;
use App\Support\AttendanceSettings;
use App\Support\FeatureSettings;
use App\Support\PayrollDeductionSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin screen for the attendance-driven salary deductions: late arrival,
 * leaving early, overstaying a break, and absence.
 *
 * Rules resolve the same two ways schedules do — a shift may carry its own
 * ladders, and everyone else falls back to the installation-wide set — so both
 * levels are edited here rather than splitting payroll config across two
 * screens.
 *
 * The rules configured here are read when a payslip is drafted; nothing on this
 * screen alters an already-issued slip.
 */
class PayrollDeductionSettingController extends Controller
{
    public function index(): Response
    {
        $shiftMode = FeatureSettings::shiftEnabled();

        return Inertia::render('payroll-deduction-settings/index', [
            'deductions' => PayrollDeductionSettings::globalRules(),
            // Each shift carries the clock its ladders are graded against, so
            // the form can label "15 menit terlambat" against the right time
            // instead of the office default.
            'shifts' => $shiftMode ? $this->shiftPanels() : [],
            'shiftMode' => $shiftMode,
            // The fallback clock: employees with no shift, and every employee
            // while shift mode is off.
            'officeHours' => AttendanceSettings::officeHours(),
            'breakWindow' => AttendanceSettings::breakWindow(),
            'breakMode' => FeatureSettings::breakEnabled(),
            // How many people actually land on the global rules. Zero here with
            // shift mode on means the global tab is currently decorative.
            'unassignedEmployees' => $shiftMode ? $this->unassignedEmployeeCount() : null,
            'limits' => [
                'max_tiers' => PayrollDeductionSettings::MAX_TIERS,
                'max_from_minutes' => PayrollDeductionSettings::MAX_FROM_MINUTES,
                'max_amount' => PayrollDeductionSettings::MAX_AMOUNT,
            ],
        ]);
    }

    /**
     * Persist the installation-wide rules.
     */
    public function update(UpdateDeductionRulesRequest $request): RedirectResponse
    {
        PayrollDeductionSettings::save($request->validated());

        return back()->with('success', 'Pengaturan potongan gaji berhasil diperbarui.');
    }

    /**
     * Persist one shift's override, or drop it so the shift follows the
     * installation-wide rules again.
     */
    public function updateShift(UpdateShiftDeductionRulesRequest $request, Shift $shift): RedirectResponse
    {
        $validated = $request->validated();

        $shift->update([
            'deduction_rules' => $validated['overrides']
                // Null is the whole signal for "follow the global rules", so
                // dropping the override must clear the column rather than store
                // a disabled copy of it.
                ? PayrollDeductionSettings::normalize($validated)
                : null,
        ]);

        return back()->with('success', "Potongan untuk shift {$shift->name} berhasil diperbarui.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shiftPanels(): array
    {
        return Shift::query()
            ->orderBy('name')
            ->withCount('employees')
            ->get()
            ->map(fn (Shift $shift): array => [
                'id' => $shift->id,
                'name' => $shift->name,
                'is_active' => $shift->is_active,
                'employees_count' => $shift->employees_count,
                'check_in' => substr($shift->check_in, 0, 5),
                'check_out' => substr($shift->check_out, 0, 5),
                'late_threshold' => substr($shift->late_threshold, 0, 5),
                'grace_minutes' => $shift->grace_minutes,
                'break_enabled' => $shift->break_enabled,
                'break_start' => $shift->break_start ? substr($shift->break_start, 0, 5) : null,
                'break_end' => $shift->break_end ? substr($shift->break_end, 0, 5) : null,
                'overrides' => $shift->hasOwnDeductionRules(),
                // Falls back to the global set when there is no override, so
                // switching the override on starts from what already applied
                // rather than from an empty form.
                'rules' => $shift->deductionRules(),
            ])
            ->all();
    }

    /**
     * Active employees with no default shift. A per-date schedule can still
     * give one of them a shift on a given day, so this is a hint for the admin
     * rather than an exact population count.
     */
    private function unassignedEmployeeCount(): int
    {
        return Employee::query()
            ->where('status', 'active')
            ->whereNull('shift_id')
            ->count();
    }
}
