<?php

namespace App\Http\Controllers\Salary;

use App\Http\Controllers\Controller;
use App\Http\Requests\Salaries\StoreSalaryRequest;
use App\Models\Employee;
use App\Models\Salary;
use App\Notifications\EmployeeNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SalaryController extends Controller
{
    /**
     * Render a payslip for printing.
     *
     * The admin check is repeated here rather than left to the route group.
     * This action renders somebody else's salary, so moving the route out of
     * that group — to let employees print their own from the dashboard, say —
     * would otherwise turn it into an unscoped read of every payslip by id.
     * The employee-facing equivalent is Api\SalaryController::print, which
     * scopes to the owner instead.
     */
    public function print(Request $request, Salary $salary): View
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $salary->load('employee.department', 'employee.position');

        return view('salaries.print', ['salary' => $salary]);
    }

    public function store(StoreSalaryRequest $request, Employee $employee): RedirectResponse
    {
        $salary = $employee->salaries()->create([
            // '!' zeroes every field the format does not carry. Without it PHP
            // fills the day from today, so a payslip filed on the 31st for a
            // 30-day month silently lands in the month after the requested one.
            'period' => Carbon::createFromFormat('!Y-m', $request->period),
            'components' => $request->components,
        ]);

        $employee->user?->notify(new EmployeeNotification(
            'Slip Gaji Terbit',
            "Slip gaji periode {$salary->period_label} sudah tersedia.",
            'salary',
        ));

        return back()->with('success', 'Slip gaji berhasil dibuat.');
    }

    public function markPaid(Salary $salary): RedirectResponse
    {
        abort_if($salary->status === 'paid', 403, 'Slip gaji ini sudah dibayar.');

        $salary->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return back()->with('success', 'Slip gaji ditandai sudah dibayar.');
    }

    public function destroy(Salary $salary): RedirectResponse
    {
        $salary->delete();

        return back()->with('success', 'Slip gaji dihapus.');
    }
}
