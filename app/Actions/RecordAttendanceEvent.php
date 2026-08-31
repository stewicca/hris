<?php

namespace App\Actions;

use App\AttendanceEventType;
use App\Models\Attendance;
use App\Models\Employee;
use App\Support\AttendanceSettings;
use Illuminate\Validation\ValidationException;

/**
 * Advances an employee's attendance timeline by exactly one event.
 *
 * Two different front doors reach this code: the authenticated portal, where
 * the session says who is checking in, and the unattended kiosk, where a face
 * does. Everything after that question is identical — which action is due, the
 * audit event row, and the mirrored day-header columns the admin views read —
 * and it is precisely the part that must not drift apart. Duplicated, every
 * future change to the attendance rules would have to be made twice, and the
 * second one would eventually be missed.
 *
 * Deliberately NOT handled here: GPS integrity and geofencing. Those belong to
 * the portal, where a phone in the field reports its own position and can lie
 * about it. A terminal bolted to a wall proves location by being that terminal.
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
        $attendance = $this->todaysAttendance($employee);

        $this->guardActionIsDue($attendance, $requestedType);

        $now = now();
        $time = $now->format('H:i:s');

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
                'face_verified' => $faceVerified,
            ],
        });

        return $attendance->fresh()->load(['events', 'shift']);
    }

    /**
     * The action the employee is expected to take next, without creating a day
     * header for it. The kiosk asks this before anything is recorded, so that
     * it can show "Selamat datang, check-in?" and let the person confirm.
     */
    public function nextActionFor(Employee $employee): ?AttendanceEventType
    {
        $attendance = $employee->attendances()->whereDate('date', today())->first();

        return $attendance
            ? $attendance->nextExpectedAction()
            : AttendanceEventType::CheckIn;
    }

    /**
     * Today's header for this employee, creating it — with the applicable shift
     * snapshotted — on the first event of the day.
     */
    private function todaysAttendance(Employee $employee): Attendance
    {
        $attendance = $employee->attendances()->whereDate('date', today())->first();

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
     *
     * Callers run this before the expensive work — a face-service round trip
     * costs CPU-bound inference, and there is no sense spending it on a request
     * that was going to be refused anyway. {@see handle()} repeats the check,
     * so skipping it here can only cost effort, never correctness.
     */
    public function assertActionIsDue(Employee $employee, AttendanceEventType $requestedType): void
    {
        $attendance = $employee->attendances()->whereDate('date', today())->first();

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
