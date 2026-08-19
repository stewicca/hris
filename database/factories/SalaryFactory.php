<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Salary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Salary>
 */
class SalaryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $base = $this->faker->numberBetween(4, 12) * 1_000_000;

        return [
            'employee_id' => Employee::factory(),
            'period' => now()->startOfMonth(),
            'components' => self::standardComponents($base),
            'status' => 'pending',
            'paid_at' => null,
        ];
    }

    /**
     * A realistic Indonesian payslip breakdown derived from a base salary.
     *
     * @return list<array{label: string, amount: int, type: string}>
     */
    public static function standardComponents(int $base): array
    {
        return [
            ['label' => 'Gaji Pokok', 'amount' => $base, 'type' => 'income'],
            ['label' => 'Tunjangan Transport', 'amount' => 500_000, 'type' => 'income'],
            ['label' => 'Tunjangan Makan', 'amount' => 750_000, 'type' => 'income'],
            ['label' => 'BPJS Kesehatan', 'amount' => (int) round($base * 0.01), 'type' => 'deduction'],
            ['label' => 'BPJS Ketenagakerjaan', 'amount' => (int) round($base * 0.02), 'type' => 'deduction'],
            ['label' => 'PPh 21', 'amount' => (int) round($base * 0.05), 'type' => 'deduction'],
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }
}
