<?php

namespace App\Actions\Attendance;

use App\AttendanceEventType;
use App\Models\Attendance;
use App\Models\Employee;
use App\Support\AttendanceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Advances an employee's attendance timeline by exactly one event.
 */
class RecordAttendanceEvent
{
    /**
     * Record the given event, returning the refreshed day header.
     *
     * @throws ValidationException When the requested action is not the one the
     *                             timeline currently expects.
     */
    public function handle(
        Employee $employee,
        AttendanceEventType $requestedType,
        ?string $photoPath = null,
        bool $faceVerified = false,
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $accuracy = null,
        ?string $notes = null,
    ): Attendance {
        abort_if($employee->status !== 'active', 403, 'Akun karyawan tidak aktif.');

        $attendance = $this->todaysAttendance($employee);

        $this->guardActionIsDue($attendance, $requestedType);

        $now = now();
        $time = $now->format('H:i:s');

        DB::transaction(function () use (
            $attendance,
            $requestedType,
            $now,
            $time,
            $latitude,
            $longitude,
            $accuracy,
            $photoPath,
            $faceVerified,
            $notes,
        ): void {
            // Source of truth: the event row with full per-action audit.
            $attendance->events()->create([
                'type' => $requestedType,
                'occurred_at' => $now,
                'lat' => $latitude,
                'lng' => $longitude,
                'accuracy' => $accuracy,
                'photo_path' => $photoPath,
                'face_verified' => $faceVerified,
                'notes' => $notes,
            ]);

            // Mirror columns kept for cheap reads across existing views/exports.
            $attendance->update(match ($requestedType) {
                AttendanceEventType::CheckIn => [
                    'check_in' => $time,
                    'check_in_lat' => $latitude,
                    'check_in_lng' => $longitude,
                    'check_in_accuracy' => $accuracy,
                    'check_in_photo_path' => $photoPath,
                    'face_verified' => $faceVerified,
                    'status' => $attendance->resolveStatus($time),
                    'notes' => $notes,
                ],
                AttendanceEventType::BreakStart => [
                    'break_start' => $time,
                ],
                AttendanceEventType::BreakEnd => [
                    'break_end' => $time,
                ],
                AttendanceEventType::CheckOut => [
                    'check_out' => $time,
                    'check_out_lat' => $latitude,
                    'check_out_lng' => $longitude,
                    'check_out_accuracy' => $accuracy,
                    'check_out_photo_path' => $photoPath,
                    'face_verified' => $attendance->face_verified && $faceVerified,
                ],
            });
        });

        return $attendance->fresh()->load(['events', 'shift']);
    }

    /**
     * Resolve the active attendance header for this employee: today's record if
     * one exists, or yesterday's open record when it belongs to an overnight
     * shift crossing midnight.
     */
    public function activeAttendance(Employee $employee): ?Attendance
    {
        $today = $employee->attendances()->whereDate('date', today())->first();

        if ($today) {
            return $today;
        }

        $yesterday = $employee->attendances()
            ->whereDate('date', today()->subDay())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->with('shift')
            ->first();

        if ($yesterday) {
            $schedule = AttendanceSettings::scheduleFromShift($yesterday->shift);

            if ($schedule['check_out'] < $schedule['check_in']) {
                return $yesterday;
            }
        }

        return null;
    }

    /**
     * The action the employee is expected to take next, without creating a day
     * header for it.
     */
    public function nextActionFor(Employee $employee): ?AttendanceEventType
    {
        $attendance = $this->activeAttendance($employee);

        return $attendance
            ? $attendance->nextExpectedAction()
            : AttendanceEventType::CheckIn;
    }

    /**
     * Today's (or active overnight) header for this employee, creating it —
     * with the applicable shift snapshotted — on the first event of the day.
     */
    private function todaysAttendance(Employee $employee): Attendance
    {
        $attendance = $this->activeAttendance($employee);

        if ($attendance) {
            return $attendance;
        }

        return $employee->attendances()->create([
            'date' => today()->toDateString(),
            'shift_id' => AttendanceSettings::resolveShift($employee, today())?->id,
        ]);
    }

    /**
     * Reject an action the timeline is not waiting for, without creating
     * anything.
     */
    public function assertActionIsDue(Employee $employee, AttendanceEventType $requestedType): void
    {
        abort_if($employee->status !== 'active', 403, 'Akun karyawan tidak aktif.');

        $attendance = $this->activeAttendance($employee);

        if ($attendance === null) {
            if ($requestedType !== AttendanceEventType::CheckIn) {
                throw ValidationException::withMessages([
                    'type' => [$this->unexpectedActionMessage(AttendanceEventType::CheckIn)],
                ]);
            }

            return;
        }

        $this->guardActionIsDue($attendance, $requestedType);
    }

    /**
     * Break is optional: from the "checked in, no break yet" state the expected
     * action is BreakStart, but CheckOut is accepted too so somebody who worked
     * straight through is not stuck.
     */
    private function guardActionIsDue(Attendance $attendance, AttendanceEventType $requestedType): void
    {
        $expected = $attendance->nextExpectedAction();

        $skipBreak = $attendance->breakEnabled()
            && $attendance->check_in !== null
            && $attendance->break_start === null
            && $requestedType === AttendanceEventType::CheckOut;

        if ($expected === $requestedType || $skipBreak) {
            return;
        }

        throw ValidationException::withMessages([
            'type' => [$this->unexpectedActionMessage($expected)],
        ]);
    }

    /**
     * Human-readable Indonesian message for an unexpected event submission.
     */
    private function unexpectedActionMessage(?AttendanceEventType $expected): string
    {
        return match ($expected) {
            null => 'Absensi hari ini sudah selesai.',
            AttendanceEventType::CheckIn => 'Silakan lakukan check-in terlebih dahulu.',
            AttendanceEventType::BreakStart => 'Belum saatnya untuk aksi ini. Silakan mulai istirahat.',
            AttendanceEventType::BreakEnd => 'Anda sedang istirahat. Silakan akhiri istirahat.',
            AttendanceEventType::CheckOut => 'Anda sudah check-in. Silakan check-out.',
        };
    }
}
