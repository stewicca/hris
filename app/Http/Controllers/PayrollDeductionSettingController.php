<?php

namespace App\Http\Controllers;

use App\Actions\Settings\UpdatePayrollDeductionSettings;
use App\Actions\Settings\UpdateShiftDeductionRules;
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

class PayrollDeductionSettingController extends Controller
{
    public function index(): Response
    {
        $shiftMode = FeatureSettings::shiftEnabled();

        return Inertia::render('payroll-deduction-settings/index', [
            'deductions' => PayrollDeductionSettings::globalRules(),
            'shifts' => $shiftMode ? $this->shiftPanels() : [],
            'shiftMode' => $shiftMode,
            'officeHours' => AttendanceSettings::officeHours(),
            'breakWindow' => AttendanceSettings::breakWindow(),
            'breakMode' => FeatureSettings::breakEnabled(),
            'unassignedEmployees' => $shiftMode ? $this->unassignedEmployeeCount() : null,
            'limits' => [
                'max_tiers' => PayrollDeductionSettings::MAX_TIERS,
                'max_from_minutes' => PayrollDeductionSettings::MAX_FROM_MINUTES,
                'max_amount' => PayrollDeductionSettings::MAX_AMOUNT,
            ],
        ]);
    }

    public function update(UpdateDeductionRulesRequest $request, UpdatePayrollDeductionSettings $update): RedirectResponse
    {
        $update->handle($request->validated());

        return back()->with('success', 'Pengaturan potongan gaji berhasil diperbarui.');
    }

    public function updateShift(UpdateShiftDeductionRulesRequest $request, Shift $shift, UpdateShiftDeductionRules $updateShift): RedirectResponse
    {
        $updateShift->handle($shift, $request->validated());

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
                'rules' => $shift->deductionRules(),
            ])
            ->all();
    }

    private function unassignedEmployeeCount(): int
    {
        return Employee::query()
            ->where('status', 'active')
            ->whereNull('shift_id')
            ->count();
    }
}
