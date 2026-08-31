<?php

namespace App\Models;

use App\AttendanceEventType;
use App\Support\AttendanceSettings;
use App\Support\FeatureSettings;
use Carbon\Carbon;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'shift_id',
        'date',
        'check_in',
        'check_out',
        'break_start',
        'break_end',
        'check_in_lat',
        'check_in_lng',
        'check_in_accuracy',
        'check_out_lat',
        'check_out_lng',
        'check_out_accuracy',
        'check_in_photo_path',
        'check_out_photo_path',
        'face_verified',
        'status',
        'notes',
        'recorded_by',
    ];

    /**
     * Statuses an admin may record by hand. 'late' is deliberately absent: it
     * is derived from the check-in time, never chosen, so a hand-written record
     * can never disagree with its own clock.
     *
     * @var list<string>
     */
    public const array MANUAL_STATUSES = ['present', 'absent', 'sick', 'permit'];

    /**
     * Statuses that record an excused day off rather than time worked. A record
     * in one of these carries no times at all.
     *
     * @var list<string>
     */
    public const array EXCUSED_STATUSES = ['sick', 'permit'];

    public function casts(): array
    {
        return [
            'date' => 'date',
            'check_in_lat' => 'float',
            'check_in_lng' => 'float',
            'check_in_accuracy' => 'float',
            'check_out_lat' => 'float',
            'check_out_lng' => 'float',
            'check_out_accuracy' => 'float',
            'face_verified' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * The admin who recorded this day by hand, if anyone did.
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return HasMany<AttendanceEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class)->orderBy('occurred_at');
    }

    /**
     * Whether every time on this record was written by an admin rather than
     * clocked by the employee. Only such a record may be deleted outright: one
     * that mixes a real check-in with a filled-in check-out still holds audit
     * data nobody is allowed to throw away.
     */
    public function isFullyManual(): bool
    {
        if ($this->recorded_by === null) {
            return false;
        }

        return $this->relationLoaded('events')
            ? $this->events->whereNull('recorded_by')->isEmpty()
            : $this->events()->whereNull('recorded_by')->doesntExist();
    }

    public function isCheckedIn(): bool
    {
        return $this->check_in !== null;
    }

    public function isCheckedOut(): bool
    {
        return $this->check_out !== null;
    }

    /**
     * Resolve the late threshold that applies to this record: the snapshot
     * shift's threshold (if shifts are enabled and one applied), otherwise the
     * global office hours default.
     *
     * @return array{threshold: string, grace_minutes: int}
     */
    public function lateThreshold(): array
    {
        $shift = FeatureSettings::shiftEnabled() ? $this->shift : null;

        if ($shift) {
            return [
                'threshold' => $shift->late_threshold,
                'grace_minutes' => $shift->grace_minutes ?? 0,
            ];
        }

        return [
            'threshold' => AttendanceSettings::officeHours()['late_threshold'],
            'grace_minutes' => 0,
        ];
    }

    /**
     * Determine late status based on the applicable late threshold. Uses the
     * snapshot shift when shifts are enabled, otherwise the global office hours.
     * A grace period (shift only) widens the allowed window before marking late.
     */
    public function resolveStatus(string $checkInTime): string
    {
        $config = $this->lateThreshold();
        $threshold = $config['threshold'];
        $threshold = strlen($threshold) === 5 ? $threshold.':00' : $threshold;

        if ($config['grace_minutes'] > 0) {
            $threshold = Carbon::createFromFormat('H:i:s', $threshold)
                ->addMinutes($config['grace_minutes'])
                ->format('H:i:s');
        }

        return $checkInTime > $threshold ? 'late' : 'present';
    }

    /**
     * Whether break tracking is active for this record: when shifts are
     * enabled, the snapshot shift's break_enabled decides; otherwise the global
     * break feature toggle.
     */
    public function breakEnabled(): bool
    {
        if (! FeatureSettings::breakEnabled()) {
            return false;
        }

        $shift = FeatureSettings::shiftEnabled() ? $this->shift : null;

        return $shift ? $shift->break_enabled : true;
    }

    /**
     * The next action a user is expected to record, based on the current
     * timeline state. Returns null when the day is complete.
     *
     * Break is optional: from the "checked in, no break yet" state the next
     * action is BreakStart, but the endpoint also accepts CheckOut to skip it.
     */
    public function nextExpectedAction(): ?AttendanceEventType
    {
        if ($this->check_out !== null) {
            return null;
        }

        if ($this->check_in === null) {
            return AttendanceEventType::CheckIn;
        }

        if ($this->breakEnabled()) {
            if ($this->break_start === null) {
                return AttendanceEventType::BreakStart;
            }

            if ($this->break_end === null) {
                return AttendanceEventType::BreakEnd;
            }
        }

        return AttendanceEventType::CheckOut;
    }

    /**
     * Net worked duration in minutes (check_in → check_out minus any break),
     * or null if the day is not complete. Uses timestamps via the record date
     * so night shifts crossing midnight compute correctly.
     */
    public function netDuration(): ?int
    {
        if ($this->check_in === null || $this->check_out === null) {
            return null;
        }

        $start = Carbon::parse("{$this->date->format('Y-m-d')} {$this->check_in}");
        $end = Carbon::parse("{$this->date->format('Y-m-d')} {$this->check_out}");

        // Night shift crossing midnight: checkout lands on the next day.
        if ($end < $start) {
            $end->addDay();
        }

        $total = $start->diffInMinutes($end);

        if ($this->break_start !== null && $this->break_end !== null) {
            $breakStart = Carbon::parse("{$this->date->format('Y-m-d')} {$this->break_start}");
            $breakEnd = Carbon::parse("{$this->date->format('Y-m-d')} {$this->break_end}");
            $total -= max(0, $breakStart->diffInMinutes($breakEnd));
        }

        return $total;
    }
}
