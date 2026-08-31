<?php

namespace App\Models;

use App\Support\PayrollDeductionSettings;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'check_in',
        'check_out',
        'late_threshold',
        'grace_minutes',
        'break_enabled',
        'break_start',
        'break_end',
        'deduction_rules',
        'is_active',
    ];

    public function casts(): array
    {
        return [
            'break_enabled' => 'boolean',
            'is_active' => 'boolean',
            'grace_minutes' => 'integer',
            'deduction_rules' => 'array',
        ];
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * @return HasMany<EmployeeSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(EmployeeSchedule::class);
    }

    /**
     * Whether this shift carries its own salary-deduction ladders instead of
     * following the installation-wide ones.
     */
    public function hasOwnDeductionRules(): bool
    {
        return is_array($this->deduction_rules);
    }

    /**
     * The deduction ladders that apply to this shift: its own when it
     * overrides, otherwise the installation-wide set.
     *
     * @return array<string, array<string, mixed>>
     */
    public function deductionRules(): array
    {
        return $this->hasOwnDeductionRules()
            ? PayrollDeductionSettings::normalize($this->deduction_rules)
            : PayrollDeductionSettings::globalRules();
    }
}
