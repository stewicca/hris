<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    public function definition(): array
    {
        $checkIn = fake()->time('H:i:s', strtotime('08:00:00') + fake()->numberBetween(0, 3600));
        $status = $checkIn > '08:05:00' ? 'late' : 'present';

        return [
            'employee_id' => Employee::factory(),
            'date' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'check_in' => $checkIn,
            'check_out' => fake()->time('H:i:s', strtotime('17:00:00') + fake()->numberBetween(-600, 3600)),
            'check_in_lat' => fake()->latitude(-11, -1),
            'check_in_lng' => fake()->longitude(95, 141),
            'check_out_lat' => fake()->latitude(-11, -1),
            'check_out_lng' => fake()->longitude(95, 141),
            'status' => $status,
        ];
    }

    public function present(): static
    {
        return $this->state(['check_in' => '07:55:00', 'status' => 'present']);
    }

    public function late(): static
    {
        return $this->state(['check_in' => '09:00:00', 'status' => 'late']);
    }

    public function noCheckOut(): static
    {
        return $this->state(['check_out' => null, 'check_out_lat' => null, 'check_out_lng' => null]);
    }
}
