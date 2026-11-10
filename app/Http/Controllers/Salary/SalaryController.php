<?php

namespace App\Http\Controllers\Salary;

use App\Actions\Salaries\CreatePayslip;
use App\Actions\Salaries\MarkPayslipPaid;
use App\Http\Controllers\Controller;
use App\Http\Requests\Salaries\StoreSalaryRequest;
use App\Models\Employee;
use App\Models\Salary;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SalaryController extends Controller
{
    public function print(Request $request, Salary $salary): View
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $salary->load('employee.department', 'employee.position');

        return view('salaries.print', ['salary' => $salary]);
    }

    public function store(StoreSalaryRequest $request, Employee $employee, CreatePayslip $createPayslip): RedirectResponse
    {
        $createPayslip->handle(
            employee: $employee,
            periodString: $request->string('period')->value(),
            components: $request->input('components', []),
        );

        return back()->with('success', 'Slip gaji berhasil dibuat.');
    }

    public function markPaid(Salary $salary, MarkPayslipPaid $markPaid): RedirectResponse
    {
        $markPaid->handle($salary);

        return back()->with('success', 'Slip gaji ditandai sudah dibayar.');
    }

    public function destroy(Salary $salary): RedirectResponse
    {
        $salary->delete();

        return back()->with('success', 'Slip gaji dihapus.');
    }
}
