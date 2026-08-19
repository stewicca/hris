<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EmployeeSeeder extends Seeder
{
    /**
     * Seed the Budi Setiawan portal test account.
     *
     * Development fixture only — a fixed account with a known password has no
     * place in production, so the seeder is a no-op there.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('EmployeeSeeder skipped: development fixture, not seeded in production.');

            return;
        }

        $user = User::firstOrCreate(
            ['username' => 'budi'],
            [
                'name' => 'Budi Setiawan',
                'email' => 'budi@hris.local',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $department = Department::firstOrCreate(['name' => 'Tech Division']);
        $position = Position::firstOrCreate(['name' => 'Senior Software Engineer']);

        Employee::firstOrCreate(
            ['user_id' => $user->id],
            [
                'employee_number' => 'EMP0001',
                'name' => 'Budi Setiawan',
                'email' => 'budi@hris.local',
                'phone' => '+628123456789',
                'bank_account_number' => '1234567890',
                'department_id' => $department->id,
                'position_id' => $position->id,
                'hire_date' => '2022-01-15',
                'status' => 'active',
            ]
        );
    }
}
