<?php

namespace App\Models;

use Database\Factories\SalaryFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Salary extends Model
{
    /** @use HasFactory<SalaryFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'period',
        'components',
        'status',
        'paid_at',
    ];

    /**
     * Derived totals are appended so the frontend never recomputes them.
     *
     * @var list<string>
     */
    protected $appends = ['gross', 'deductions', 'net', 'period_label'];

    public function casts(): array
    {
        return [
            'period' => 'date',
            'components' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Sum of every income component.
     */
    protected function gross(): Attribute
    {
        return Attribute::get(fn (): int => $this->sumComponents('income'));
    }

    /**
     * Sum of every deduction component.
     */
    protected function deductions(): Attribute
    {
        return Attribute::get(fn (): int => $this->sumComponents('deduction'));
    }

    /**
     * Take-home pay: gross minus deductions.
     */
    protected function net(): Attribute
    {
        return Attribute::get(fn (): int => $this->gross - $this->deductions);
    }

    /**
     * Human label for the pay period, e.g. "Juni 2026".
     */
    protected function periodLabel(): Attribute
    {
        return Attribute::get(fn (): string => $this->period->locale('id')->translatedFormat('F Y'));
    }

    private function sumComponents(string $type): int
    {
        return (int) collect($this->components ?? [])
            ->where('type', $type)
            ->sum('amount');
    }
}
