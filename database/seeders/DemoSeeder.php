<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\Salary;
use App\Models\User;
use Database\Factories\SalaryFactory;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    /**
     * Seed realistic demo data (employees + ~1 month attendance + mixed leaves)
     * so the dashboard, recap, and approval flow have something to show.
     *
     * Development only — refuses to run when APP_ENV=production.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('DemoSeeder skipped: fabricated data, not seeded in production.');

            return;
        }

        // ponytail: idempotency guard — only the budi fixture exists on a fresh DB.
        // Bail if demo data is already present so `db:seed` twice doesn't duplicate.
        if (Employee::count() > 1) {
            return;
        }

        // EMP0001 belongs to budi (EmployeeSeeder), so start at EMP0002.
        $employees = Employee::factory()
            ->count(8)
            ->active()
            ->sequence(fn ($seq) => ['employee_number' => 'EMP'.str_pad((string) ($seq->index + 2), 4, '0', STR_PAD_LEFT)])
            ->create();

        // Include budi so the portal test account has history too.
        $employees = $employees->concat(Employee::where('employee_number', 'EMP0001')->get());

        foreach ($employees as $employee) {
            $this->seedAttendance($employee);
            $this->seedLeaves($employee);
            $this->seedSalaries($employee);
        }
    }

    /** One attendance row per weekday for the last 30 days, ~10% absent (no row). */
    private function seedAttendance(Employee $employee): void
    {
        foreach (range(0, 30) as $daysAgo) {
            $date = today()->subDays($daysAgo);

            if ($date->isWeekend() || fake()->boolean(10)) {
                continue;
            }

            $factory = Attendance::factory();

            // Today is still in progress for some people: checked in, not out yet.
            if ($daysAgo === 0 && fake()->boolean(50)) {
                $factory = $factory->noCheckOut();
            }

            $factory->create([
                'employee_id' => $employee->id,
                'date' => $date->toDateString(),
            ]);
        }
    }

    /** A mix of pending/approved/rejected so the approval flow has work to do. */
    private function seedLeaves(Employee $employee): void
    {
        $approver = User::where('username', 'admin')->value('id');

        Leave::factory()->create(['employee_id' => $employee->id]); // pending
        Leave::factory()->approved()->create(['employee_id' => $employee->id, 'approved_by' => $approver]);

        if (fake()->boolean(40)) {
            Leave::factory()->rejected()->create(['employee_id' => $employee->id, 'approved_by' => $approver]);
        }
    }

    /** Three months of payslips: prior months paid, current month still pending. */
    private function seedSalaries(Employee $employee): void
    {
        $base = fake()->numberBetween(4, 12) * 1_000_000;

        foreach (range(0, 2) as $monthsAgo) {
            $factory = Salary::factory();

            if ($monthsAgo > 0) {
                $factory = $factory->paid();
            }

            $factory->create([
                'employee_id' => $employee->id,
                'period' => today()->subMonths($monthsAgo)->startOfMonth(),
                'components' => SalaryFactory::standardComponents($base),
            ]);
        }
    }
}
