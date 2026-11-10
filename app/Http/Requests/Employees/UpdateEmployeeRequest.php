<?php

namespace App\Http\Requests\Employees;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $employee = $this->route('employee');
        $employeeId = $employee?->id;
        $userId = $employee?->user_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                "unique:employees,email,{$employeeId}",
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'hire_date' => ['nullable', 'date'],
            'status' => ['required', 'in:active,inactive'],
            'is_admin' => ['nullable', 'boolean'],
            'annual_leave_quota' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
