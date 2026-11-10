<?php

namespace App\Actions\Attendance;

use App\AttendanceEventType;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\EmployeeNotification;
use App\Support\AttendanceSettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Writes an attendance day on behalf of an employee who did not clock it.
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
        $attendance = DB::transaction(function () use ($employee, $date, $status, $times, $notes, $recordedBy): Attendance {
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
        });

        $employee->user?->notify(new EmployeeNotification(
            'Kehadiran Dicatat Admin',
            sprintf(
                'Kehadiran Anda tanggal %s dicatat sebagai "%s" oleh admin.',
                $attendance->date->format('d M Y'),
                Attendance::statusLabel($attendance->status),
            ),
            'info',
        ));

        return $attendance;
    }

    /**
     * The day header for this employee and date, snapshotting the shift that
     * applied on that date when the row has to be created.
     */
    private function headerFor(Employee $employee, CarbonInterface $date): Attendance
    {
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
     * @param  array<string, string|null>  $times
     * @return list<string>
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
