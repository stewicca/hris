<?php

namespace App\Actions;

use App\AttendanceEventType;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use App\Support\AttendanceSettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Writes an attendance day on behalf of an employee who did not clock it.
 *
 * The portal and the kiosk both advance a timeline one event at a time, from a
 * live person standing there with a phone or a face. Neither can help the two
 * cases this action exists for: somebody who forgot to check in and only says
 * so afterwards, and somebody who never came in at all and is marked sick or
 * excused — which is the only way to record either once the leave module is
 * switched off.
 *
 * Every row it writes carries the admin's id, on the day header and on each
 * event it touches, so a filled-in time can always be told apart from a
 * clocked one. Times the admin left exactly as they were are not rewritten, so
 * a real check-in keeps its GPS and photo when only the check-out is added.
 * Correcting a time the employee did clock replaces that event's audit data:
 * the correction is the point, and `recorded_by` records who made it.
 */
class RecordManualAttendance
{
    /**
     * The mirror columns this action maintains, paired with the event type each
     * one summarises.
     */
    private const array TIME_COLUMNS = [
        'check_in' => AttendanceEventType::CheckIn,
        'break_start' => AttendanceEventType::BreakStart,
        'break_end' => AttendanceEventType::BreakEnd,
        'check_out' => AttendanceEventType::CheckOut,
    ];

    /**
     * Record or correct one employee's day.
     *
     * A status other than 'present' describes a day nobody worked, so any times
     * submitted alongside it are dropped and the events behind them deleted —
     * an employee cannot be both absent and checked in at 08:00.
     *
     * @param  array<string, string|null>  $times  H:i values keyed by mirror column
     */
    public function handle(
        Employee $employee,
        CarbonInterface $date,
        string $status,
        array $times,
        ?string $notes,
        User $recordedBy,
    ): Attendance {
        $attendance = $this->headerFor($employee, $date);

        $times = $status === 'present'
            ? $this->normalizeTimes($times)
            : array_fill_keys(array_keys(self::TIME_COLUMNS), null);

        $touched = $this->syncEvents($attendance, $times, $recordedBy);

        $attendance->update([
            ...$times,
            ...$this->clearedAuditColumns($touched),
            'status' => $times['check_in'] !== null
                ? $attendance->resolveStatus($times['check_in'])
                : $status,
            'notes' => $notes,
            'recorded_by' => $recordedBy->id,
        ]);

        return $attendance->fresh();
    }

    /**
     * The day header for this employee and date, snapshotting the shift that
     * applied on that date when the row has to be created.
     */
    private function headerFor(Employee $employee, CarbonInterface $date): Attendance
    {
        // whereDate, not a plain where: the column holds a full datetime, so
        // comparing it against a bare Y-m-d string never matches and every save
        // would try to insert a second row for the same day.
        $attendance = $employee->attendances()->whereDate('date', $date)->first();

        if ($attendance) {
            return $attendance;
        }

        return $employee->attendances()->create([
            'date' => $date->toDateString(),
            'shift_id' => AttendanceSettings::resolveShift($employee, $date)?->id,
        ]);
    }

    /**
     * Widen H:i values to the H:i:s the mirror columns store, so they compare
     * directly against what is already on the record.
     *
     * @param  array<string, string|null>  $times
     * @return array<string, string|null>
     */
    private function normalizeTimes(array $times): array
    {
        $normalized = [];

        foreach (array_keys(self::TIME_COLUMNS) as $column) {
            $value = $times[$column] ?? null;
            $normalized[$column] = $value === null || $value === ''
                ? null
                : substr($value, 0, 5).':00';
        }

        return $normalized;
    }

    /**
     * Bring the event timeline in line with the submitted times, leaving
     * untouched times — and therefore their original audit data — alone.
     *
     * @param  array<string, string|null>  $times
     * @return list<string> the columns that were actually rewritten
     */
    private function syncEvents(Attendance $attendance, array $times, User $recordedBy): array
    {
        $touched = [];

        foreach (self::TIME_COLUMNS as $column => $type) {
            if ($times[$column] === $attendance->{$column}) {
                continue;
            }

            $touched[] = $column;
            $event = $attendance->events()->where('type', $type)->first();

            if ($times[$column] === null) {
                $event?->delete();

                continue;
            }

            $payload = [
                'occurred_at' => $this->occurredAt($attendance, $times, $column),
                'lat' => null,
                'lng' => null,
                'accuracy' => null,
                'photo_path' => null,
                'face_verified' => false,
                'recorded_by' => $recordedBy->id,
            ];

            $event
                ? $event->update($payload)
                : $attendance->events()->create([...$payload, 'type' => $type]);
        }

        return $touched;
    }

    /**
     * Place a time on the calendar. Anything earlier than the check-in belongs
     * to the next day, so a night shift that ends at 06:00 does not read as
     * sixteen hours before it started.
     *
     * @param  array<string, string|null>  $times
     */
    private function occurredAt(Attendance $attendance, array $times, string $column): CarbonInterface
    {
        $at = Carbon::parse("{$attendance->date->format('Y-m-d')} {$times[$column]}");
        $checkIn = $times['check_in'];

        if ($column !== 'check_in' && $checkIn !== null && $times[$column] < $checkIn) {
            $at->addDay();
        }

        return $at;
    }

    /**
     * The legacy per-side GPS and photo columns belong to the event they were
     * captured with. Once that time is rewritten by hand they describe nothing,
     * so they are cleared rather than left pointing at a moment that no longer
     * exists on the record.
     *
     * @param  list<string>  $touched
     * @return array<string, null|false>
     */
    private function clearedAuditColumns(array $touched): array
    {
        $cleared = [];

        foreach (['check_in', 'check_out'] as $side) {
            if (! in_array($side, $touched, true)) {
                continue;
            }

            $cleared["{$side}_lat"] = null;
            $cleared["{$side}_lng"] = null;
            $cleared["{$side}_accuracy"] = null;
            $cleared["{$side}_photo_path"] = null;
            $cleared['face_verified'] = false;
        }

        return $cleared;
    }
}
