<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Salary;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalaryController extends Controller
{
    public function print(Request $request, Salary $salary): View
    {
        abort_unless($salary->employee_id === $request->user()->employee?->id, 403);

        $salary->load('employee.department', 'employee.position');

        return view('salaries.print', ['salary' => $salary]);
    }

    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['message' => 'Employee profile not found.'], 404);
        }

        $salaries = $employee->salaries()
            ->orderByDesc('period')
            ->get()
            ->map(fn (Salary $salary): array => [
                'id' => (string) $salary->id,
                'period' => $salary->period_label,
                'status' => $salary->status,
                'gross' => $salary->gross,
                'deductions' => $salary->deductions,
                'net' => $salary->net,
                'components' => $salary->components,
            ]);

        return response()->json(['salaries' => $salaries]);
    }
}
