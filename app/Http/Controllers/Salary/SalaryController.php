<?php

namespace App\Http\Controllers\Salary;

use App\Http\Controllers\Controller;
use App\Http\Requests\Salaries\StoreSalaryRequest;
use App\Models\Employee;
use App\Models\Salary;
use App\Notifications\EmployeeNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

class SalaryController extends Controller
{
    public function print(Salary $salary): View
    {
        $salary->load('employee.department', 'employee.position');

        return view('salaries.print', ['salary' => $salary]);
    }

    public function store(StoreSalaryRequest $request, Employee $employee): RedirectResponse
    {
        $salary = $employee->salaries()->create([
            'period' => Carbon::createFromFormat('Y-m', $request->period)->startOfMonth(),
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
