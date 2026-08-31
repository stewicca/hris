<?php

namespace App\Http\Requests\Salaries;

use App\Models\Salary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class StoreSalaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $employeeId = $this->route('employee')->id;

        return [
            'period' => [
                'bail',
                'required',
                'date_format:Y-m',
                function (string $attribute, string $value, \Closure $fail) use ($employeeId): void {
                    // '!' pins the day to the 1st; without it the day comes
                    // from today and the lookup checks the wrong month.
                    $period = Carbon::createFromFormat('!Y-m', $value);

                    $exists = Salary::where('employee_id', $employeeId)
                        ->whereYear('period', $period->year)
                        ->whereMonth('period', $period->month)
                        ->exists();

                    if ($exists) {
                        $fail('Slip gaji untuk periode ini sudah ada.');
                    }
                },
            ],
            'components' => ['required', 'array', 'min:1'],
            'components.*.label' => ['required', 'string', 'max:100'],
            'components.*.amount' => ['required', 'integer', 'min:0'],
            'components.*.type' => ['required', 'in:income,deduction'],
        ];
    }
}
