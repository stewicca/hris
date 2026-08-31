<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'employee_number',
        'name',
        'email',
        'phone',
        'bank_account_number',
        'department_id',
        'position_id',
        'shift_id',
        'hire_date',
        'status',
        'annual_leave_quota',
        'face_embedding',
        'face_photo_path',
        'face_enrolled_at',
    ];

    public function casts(): array
    {
        return [
            'hire_date' => 'date',
            'annual_leave_quota' => 'integer',
            'face_embedding' => 'array',
            'face_enrolled_at' => 'datetime',
        ];
    }

    /**
     * Whether a reference face has been enrolled for this employee.
     */
    public function isFaceEnrolled(): bool
    {
        return $this->face_embedding !== null && $this->face_embedding !== [];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(EmployeeSchedule::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class);
    }

    public function salaries(): HasMany
    {
        return $this->hasMany(Salary::class);
    }

    /**
     * The shift that applies to this employee on the given date. A per-date
     * schedule override (rotation) wins over the default shift assignment.
     * Returns null when no shift applies (global office hours are used).
     */
    public function shiftForDate(CarbonInterface $date): ?Shift
    {
        return $this->schedules()
            ->whereDate('date', $date->toDateString())
            ->with('shift')
            ->first()
            ?->shift
            ?? ($this->shift_id ? $this->shift : null);
    }

    /**
     * Annual leave balance for the given year (pending requests are reserved).
     *
     * @return array{quota: int, used: int, pending: int, remaining: int}
     */
    public function annualLeaveSummary(?int $year = null): array
    {
        $year ??= now()->year;

        $leaves = $this->leaves()
            ->where('type', 'annual')
            ->whereIn('status', ['pending', 'approved'])
            ->whereYear('start_date', $year)
            ->get(['status', 'days']);

        $used = (int) $leaves->where('status', 'approved')->sum('days');
        $pending = (int) $leaves->where('status', 'pending')->sum('days');

        return [
            'quota' => $this->annual_leave_quota,
            'used' => $used,
            'pending' => $pending,
            'remaining' => max(0, $this->annual_leave_quota - $used - $pending),
        ];
    }

    /**
     * Generate the next employee number.
     *
     * Must be called inside the same transaction that inserts the row. The
     * read is locked, so a second creator blocks here until the first has
     * committed and then reads the number that was actually written. Without
     * the lock both compute the same value and one insert dies on the unique
     * index.
     */
    public static function generateEmployeeNumber(): string
    {
        $latest = static::query()
            ->latest('id')
            ->lockForUpdate()
            ->value('employee_number');

        $next = $latest ? (int) substr($latest, 3) + 1 : 1;

        return 'EMP'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
