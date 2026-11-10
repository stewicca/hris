<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;
use App\Support\UsernameGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateEmployee
{
    private const int CREATE_ATTEMPTS = 3;

    /**
     * Create an employee and linked user account inside a transaction,
     * returning the generated plaintext password.
     *
     * @param  array<string, mixed>  $validated
     */
    public function handle(array $validated, bool $isAdmin, ?int $annualLeaveQuota = null): string
    {
        $plainPassword = Str::password(12, symbols: false);

        foreach (range(1, self::CREATE_ATTEMPTS) as $attempt) {
            try {
                DB::transaction(function () use ($validated, $isAdmin, $annualLeaveQuota, $plainPassword): void {
                    $user = User::create([
                        'name' => $validated['name'],
                        'username' => UsernameGenerator::generate($validated['name']),
                        'email' => $validated['email'],
                        'password' => Hash::make($plainPassword),
                        'is_admin' => $isAdmin,
                    ]);

                    Employee::create([
                        ...$validated,
                        'user_id' => $user->id,
                        'employee_number' => Employee::generateEmployeeNumber(),
                        'annual_leave_quota' => $annualLeaveQuota ?? 12,
                    ]);
                });

                return $plainPassword;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === self::CREATE_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        return $plainPassword;
    }
}
