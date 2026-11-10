<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResetEmployeePassword
{
    public function handle(Employee $employee): string
    {
        $plainPassword = Str::password(12, symbols: false);

        $employee->user->update([
            'password' => Hash::make($plainPassword),
        ]);

        return $plainPassword;
    }
}
