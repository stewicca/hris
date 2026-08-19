<?php

namespace Database\Factories;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'check_in' => '08:00:00',
            'check_out' => '17:00:00',
            'late_threshold' => '08:05:00',
            'grace_minutes' => 0,
            'break_enabled' => false,
            'break_start' => null,
            'break_end' => null,
            'is_active' => true,
        ];
    }

    public function withBreak(): static
    {
        return $this->state([
            'break_enabled' => true,
            'break_start' => '12:00:00',
            'break_end' => '13:00:00',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
