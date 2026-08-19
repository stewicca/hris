<?php

namespace Database\Factories;

use App\AttendanceEventType;
use App\Models\Attendance;
use App\Models\AttendanceEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceEvent>
 */
class AttendanceEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'attendance_id' => Attendance::factory(),
            'type' => AttendanceEventType::CheckIn,
            'occurred_at' => now(),
            'face_verified' => true,
        ];
    }
}
