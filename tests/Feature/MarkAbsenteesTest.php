<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

it('marks active employees without attendance as absent on a working day', function () {
    $present = Employee::factory()->active()->create();
    Attendance::factory()->create([
        'employee_id' => $present->id,
        'date' => '2026-06-29',
        'status' => 'present',
    ]);
    $noShow = Employee::factory()->active()->create();

    $this->artisan('attendance:mark-absentees', ['--date' => '2026-06-29'])->assertSuccessful();

    expect(Attendance::where('employee_id', $noShow->id)->whereDate('date', '2026-06-29')->where('status', 'absent')->exists())->toBeTrue()
        ->and(Attendance::where('employee_id', $present->id)->where('status', 'present')->exists())->toBeTrue();
});

it('skips employees on approved leave', function () {
    $employee = Employee::factory()->active()->create();
    Leave::factory()->approved()->create([
        'employee_id' => $employee->id,
        'start_date' => '2026-06-29',
        'end_date' => '2026-06-29',
    ]);

    $this->artisan('attendance:mark-absentees', ['--date' => '2026-06-29'])->assertSuccessful();

    expect(Attendance::where('employee_id', $employee->id)->exists())->toBeFalse();
});

it('does nothing on a non-working day', function () {
    Employee::factory()->active()->create();

    $this->artisan('attendance:mark-absentees', ['--date' => '2026-06-28'])->assertSuccessful(); // Sunday

    expect(Attendance::count())->toBe(0);
});

it('skips inactive employees', function () {
    Employee::factory()->inactive()->create();

    $this->artisan('attendance:mark-absentees', ['--date' => '2026-06-29'])->assertSuccessful();

    expect(Attendance::count())->toBe(0);
});

it('is idempotent when run twice', function () {
    $employee = Employee::factory()->active()->create();

    $this->artisan('attendance:mark-absentees', ['--date' => '2026-06-29'])->assertSuccessful();
    $this->artisan('attendance:mark-absentees', ['--date' => '2026-06-29'])->assertSuccessful();

    expect(Attendance::where('employee_id', $employee->id)->count())->toBe(1);
});
