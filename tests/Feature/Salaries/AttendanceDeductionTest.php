<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Salary;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Support\AttendanceDeduction;
use App\Support\PayrollDeductionSettings;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

/** Store one rule group on top of the all-off defaults. */
function storeRules(array $override): void
{
    PayrollDeductionSettings::save(array_replace_recursive(PayrollDeductionSettings::DEFAULTS, $override));
}

/** A ladder costing 15.000 from fifteen minutes and 40.000 from thirty. */
function lateLadderRules(string $basis = PayrollDeductionSettings::BASIS_CHECK_IN): array
{
    return ['late' => [
        'enabled' => true,
        'basis' => $basis,
        'tiers' => [
            ['from_minutes' => 15, 'amount' => 15_000],
            ['from_minutes' => 30, 'amount' => 40_000],
        ],
    ]];
}

/** One day of attendance, priced by the calculator. */
function priceDay(array $attributes, ?Employee $employee = null): AttendanceDeduction
{
    $employee ??= Employee::factory()->active()->create();

    $attendance = Attendance::factory()->create([
        'employee_id' => $employee->id,
        'date' => '2026-06-10',
        ...$attributes,
    ]);

    return AttendanceDeduction::for($attendance->setRelation('employee', $employee));
}

// --- lateness ---

it('deducts nothing while every rule is off', function () {
    $deduction = priceDay(['check_in' => '10:00:00', 'check_out' => '17:00:00', 'status' => 'late']);

    expect($deduction->total)->toBe(0)
        ->and($deduction->isEmpty())->toBeTrue();
});

it('charges the rung the lateness reaches', function () {
    storeRules(lateLadderRules());

    expect(priceDay(['check_in' => '08:20:00', 'status' => 'late'])->total)->toBe(15_000);
});

it('charges the deepest rung reached rather than the sum of them', function () {
    storeRules(lateLadderRules());

    expect(priceDay(['check_in' => '08:45:00', 'status' => 'late'])->total)->toBe(40_000);
});

it('charges nothing for arriving before the scheduled time', function () {
    storeRules(lateLadderRules());

    expect(priceDay(['check_in' => '07:55:00', 'status' => 'present'])->total)->toBe(0);
});

it('counts lateness from the scheduled check-in by default', function () {
    storeRules(lateLadderRules());

    // 08:19 is nineteen minutes past the 08:00 start: past the first rung.
    expect(priceDay(['check_in' => '08:19:00', 'status' => 'late'])->total)->toBe(15_000);
});

it('counts lateness from the threshold when the rules say so', function () {
    storeRules(lateLadderRules(PayrollDeductionSettings::BASIS_LATE_THRESHOLD));

    // The same 08:19, now measured from the 08:05 threshold: fourteen minutes,
    // one short of the first rung.
    expect(priceDay(['check_in' => '08:19:00', 'status' => 'late'])->total)->toBe(0);
});

it('spends a shift grace period before charging under the threshold basis', function () {
    Setting::set('attendance_shift_enabled', true);
    storeRules(lateLadderRules(PayrollDeductionSettings::BASIS_LATE_THRESHOLD));

    // Threshold 08:05 plus ten minutes' grace: charging starts at 08:15.
    $shift = Shift::factory()->create(['grace_minutes' => 10]);
    $employee = Employee::factory()->active()->create(['shift_id' => $shift->id]);

    expect(priceDay(['check_in' => '08:29:00', 'status' => 'late', 'shift_id' => $shift->id], $employee)->total)
        ->toBe(0);

    $employee->attendances()->delete();

    expect(priceDay(['check_in' => '08:31:00', 'status' => 'late', 'shift_id' => $shift->id], $employee)->total)
        ->toBe(15_000);
});

// --- leaving early ---

it('charges for leaving early', function () {
    storeRules(['early_leave' => ['enabled' => true, 'tiers' => [['from_minutes' => 15, 'amount' => 20_000]]]]);

    expect(priceDay(['check_in' => '08:00:00', 'check_out' => '16:30:00', 'status' => 'present'])->total)
        ->toBe(20_000);
});

it('does not charge for leaving early when the clock-out is missing', function () {
    storeRules(['early_leave' => ['enabled' => true, 'tiers' => [['from_minutes' => 1, 'amount' => 20_000]]]]);

    expect(priceDay(['check_in' => '08:00:00', 'check_out' => null, 'status' => 'present'])->total)->toBe(0);
});

it('does not charge for staying past the scheduled end', function () {
    storeRules(['early_leave' => ['enabled' => true, 'tiers' => [['from_minutes' => 1, 'amount' => 20_000]]]]);

    expect(priceDay(['check_in' => '08:00:00', 'check_out' => '17:30:00', 'status' => 'present'])->total)->toBe(0);
});

// --- break ---

it('charges a break by its length rather than by the clock', function () {
    Setting::set('attendance_break_enabled', true);
    storeRules(['break_overrun' => ['enabled' => true, 'tiers' => [['from_minutes' => 1, 'amount' => 10_000]]]]);

    // Half an hour late to a 12:00–13:00 break, back half an hour late: the
    // full hour was taken and nothing was overrun.
    $deduction = priceDay([
        'check_in' => '08:00:00',
        'check_out' => '17:00:00',
        'break_start' => '12:30:00',
        'break_end' => '13:30:00',
        'status' => 'present',
    ]);

    expect($deduction->total)->toBe(0);
});

it('charges a break that ran longer than its allotment', function () {
    Setting::set('attendance_break_enabled', true);
    storeRules(['break_overrun' => ['enabled' => true, 'tiers' => [['from_minutes' => 15, 'amount' => 10_000]]]]);

    $deduction = priceDay([
        'check_in' => '08:00:00',
        'check_out' => '17:00:00',
        'break_start' => '12:00:00',
        'break_end' => '13:25:00',
        'status' => 'present',
    ]);

    expect($deduction->total)->toBe(10_000);
});

it('ignores the break rule while break tracking is off', function () {
    storeRules(['break_overrun' => ['enabled' => true, 'tiers' => [['from_minutes' => 1, 'amount' => 10_000]]]]);

    $deduction = priceDay([
        'check_in' => '08:00:00',
        'check_out' => '17:00:00',
        'break_start' => '12:00:00',
        'break_end' => '14:00:00',
        'status' => 'present',
    ]);

    expect($deduction->total)->toBe(0);
});

// --- days nobody worked ---

it('charges a flat amount for an absence', function () {
    storeRules(['absent' => ['enabled' => true, 'amount' => 100_000]]);

    $deduction = priceDay(['check_in' => null, 'check_out' => null, 'status' => 'absent']);

    expect($deduction->total)->toBe(100_000)
        ->and($deduction->lines['absent']['minutes'])->toBeNull();
});

it('charges nothing for a day recorded as sick or excused', function (string $status) {
    storeRules([
        ...lateLadderRules(),
        'absent' => ['enabled' => true, 'amount' => 100_000],
    ]);

    expect(priceDay(['check_in' => null, 'check_out' => null, 'status' => $status])->total)->toBe(0);
})->with(['sick', 'permit']);

// --- several rules at once ---

it('adds up every rule a single day broke', function () {
    storeRules([
        ...lateLadderRules(),
        'early_leave' => ['enabled' => true, 'tiers' => [['from_minutes' => 15, 'amount' => 20_000]]],
    ]);

    $deduction = priceDay(['check_in' => '08:20:00', 'check_out' => '16:30:00', 'status' => 'late']);

    expect($deduction->total)->toBe(35_000)
        ->and(array_keys($deduction->lines))->toBe(['late', 'early_leave']);
});

// --- night shifts ---

it('measures a night shift against its own clock', function () {
    Setting::set('attendance_shift_enabled', true);
    storeRules(['early_leave' => ['enabled' => true, 'tiers' => [['from_minutes' => 30, 'amount' => 25_000]]]]);

    $shift = Shift::factory()->create([
        'check_in' => '22:00:00',
        'check_out' => '06:00:00',
        'late_threshold' => '22:05:00',
    ]);
    $employee = Employee::factory()->active()->create(['shift_id' => $shift->id]);

    // Clocked out at 05:00 the following morning: an hour early, not nineteen
    // hours late.
    $deduction = priceDay([
        'check_in' => '22:00:00',
        'check_out' => '05:00:00',
        'status' => 'present',
        'shift_id' => $shift->id,
    ], $employee);

    expect($deduction->total)->toBe(25_000)
        ->and($deduction->lines['early_leave']['minutes'])->toBe(60);
});

it('does not read a check-out after midnight as leaving early', function () {
    storeRules(['early_leave' => ['enabled' => true, 'tiers' => [['from_minutes' => 1, 'amount' => 25_000]]]]);

    // A day shift worked eight hours past its 17:00 end.
    expect(priceDay(['check_in' => '08:00:00', 'check_out' => '01:00:00', 'status' => 'present'])->total)->toBe(0);
});

// --- which rules a past record is priced against ---

it('prices a record against the shift it snapshotted', function () {
    Setting::set('attendance_shift_enabled', true);

    $morning = Shift::factory()->create([
        'deduction_rules' => PayrollDeductionSettings::normalize(array_replace_recursive(
            PayrollDeductionSettings::DEFAULTS,
            ['late' => ['enabled' => true, 'basis' => 'check_in', 'tiers' => [['from_minutes' => 5, 'amount' => 77_000]]]],
        )),
    ]);

    // The employee has since been moved to a shift that forgives lateness.
    $evening = Shift::factory()->create(['check_in' => '22:00:00', 'check_out' => '06:00:00', 'late_threshold' => '22:05:00']);
    $employee = Employee::factory()->active()->create(['shift_id' => $evening->id]);

    $deduction = priceDay([
        'check_in' => '08:20:00',
        'status' => 'late',
        'shift_id' => $morning->id,
    ], $employee);

    expect($deduction->total)->toBe(77_000);
});

it('falls back to the shift resolved today for a record with no snapshot', function () {
    Setting::set('attendance_shift_enabled', true);
    storeRules(['absent' => ['enabled' => true, 'amount' => 10_000]]);

    $shift = Shift::factory()->create([
        'deduction_rules' => PayrollDeductionSettings::normalize(array_replace_recursive(
            PayrollDeductionSettings::DEFAULTS,
            ['absent' => ['enabled' => true, 'amount' => 250_000]],
        )),
    ]);
    $employee = Employee::factory()->active()->create(['shift_id' => $shift->id]);

    $deduction = priceDay([
        'check_in' => null,
        'check_out' => null,
        'status' => 'absent',
        'shift_id' => null,
    ], $employee);

    expect($deduction->total)->toBe(250_000);
});

it('snapshots the shift when marking absentees', function () {
    Setting::set('attendance_shift_enabled', true);

    $shift = Shift::factory()->create();
    $employee = Employee::factory()->active()->create(['shift_id' => $shift->id]);

    // A Wednesday, so the work calendar counts it.
    $this->travelTo('2026-06-10 20:00:00');
    $this->artisan('attendance:mark-absentees')->assertSuccessful();

    expect($employee->attendances()->sole()->shift_id)->toBe($shift->id);
});

// --- rolling a month up ---

it('sums a month and leaves the neighbouring ones alone', function () {
    storeRules(lateLadderRules());

    $employee = Employee::factory()->active()->create();

    foreach (['2026-05-29', '2026-06-10', '2026-06-11', '2026-07-01'] as $date) {
        Attendance::factory()->create([
            'employee_id' => $employee->id,
            'date' => $date,
            'check_in' => '08:20:00',
            'check_out' => '17:00:00',
            'status' => 'late',
        ]);
    }

    $deduction = AttendanceDeduction::forMonth($employee, Carbon::createFromFormat('!Y-m', '2026-06'));

    expect($deduction->total)->toBe(30_000)
        ->and($deduction->lines['late']['minutes'])->toBe(40);
});

it('describes each deduction in a breakdown a report can print', function () {
    storeRules([
        ...lateLadderRules(),
        'early_leave' => ['enabled' => true, 'tiers' => [['from_minutes' => 15, 'amount' => 20_000]]],
    ]);

    $deduction = priceDay(['check_in' => '08:20:00', 'check_out' => '16:30:00', 'status' => 'late']);

    expect($deduction->reason())->toBe('Terlambat 20 mnt: 15.000; Pulang cepat 30 mnt: 20.000');
});

it('names the absence in a breakdown without inventing minutes for it', function () {
    storeRules(['absent' => ['enabled' => true, 'amount' => 100_000]]);

    $deduction = priceDay(['check_in' => null, 'check_out' => null, 'status' => 'absent']);

    expect($deduction->reason())->toBe('Tidak hadir: 100.000');
});

it('turns a month into one payslip component per rule broken', function () {
    storeRules([
        ...lateLadderRules(),
        'absent' => ['enabled' => true, 'amount' => 100_000],
    ]);

    $employee = Employee::factory()->active()->create();

    Attendance::factory()->create([
        'employee_id' => $employee->id,
        'date' => '2026-06-10',
        'check_in' => '08:20:00',
        'check_out' => '17:00:00',
        'status' => 'late',
    ]);
    Attendance::factory()->create([
        'employee_id' => $employee->id,
        'date' => '2026-06-11',
        'check_in' => null,
        'check_out' => null,
        'status' => 'absent',
    ]);

    $components = AttendanceDeduction::forMonth($employee, Carbon::createFromFormat('!Y-m', '2026-06'))
        ->salaryComponents();

    expect($components)->toBe([
        ['label' => 'Potongan Keterlambatan', 'amount' => 15_000, 'type' => 'deduction'],
        ['label' => 'Potongan Ketidakhadiran', 'amount' => 100_000, 'type' => 'deduction'],
    ]);
});

// --- the export ---

it('adds the deduction columns to the attendance export', function () {
    storeRules(lateLadderRules());

    $employee = Employee::factory()->active()->create();
    Attendance::factory()->create([
        'employee_id' => $employee->id,
        'date' => '2026-06-10',
        'check_in' => '08:20:00',
        'check_out' => '17:00:00',
        'status' => 'late',
    ]);

    $csv = $this->actingAs($this->admin)
        ->get(route('employees.attendance.export', $employee))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Potongan,"Rincian Potongan"')
        ->and($csv)->toContain('15000,"Terlambat 20 mnt: 15.000"');
});

it('writes a zero and a dash for a day that cost nothing', function () {
    $employee = Employee::factory()->active()->create();
    Attendance::factory()->create([
        'employee_id' => $employee->id,
        'date' => '2026-06-10',
        'check_in' => '08:00:00',
        'check_out' => '17:00:00',
        'status' => 'present',
    ]);

    $csv = $this->actingAs($this->admin)
        ->get(route('employees.attendance.export', $employee))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Hadir,0,-');
});

// --- the payslip ---

it('adds the attendance deduction to a new payslip', function () {
    $this->travelTo('2026-08-31 09:00:00');
    storeRules(lateLadderRules());

    $employee = Employee::factory()->active()->create();
    Attendance::factory()->create([
        'employee_id' => $employee->id,
        'date' => '2026-06-10',
        'check_in' => '08:20:00',
        'check_out' => '17:00:00',
        'status' => 'late',
    ]);

    $this->actingAs($this->admin)
        ->post(route('employees.salaries.store', $employee), [
            'period' => '2026-06',
            'components' => [['label' => 'Gaji Pokok', 'amount' => 5_000_000, 'type' => 'income']],
        ])
        ->assertRedirect();

    $salary = Salary::sole();

    expect($salary->deductions)->toBe(15_000)
        ->and($salary->net)->toBe(4_985_000)
        ->and(collect($salary->components)->pluck('label'))
        ->toContain('Potongan Keterlambatan');
});

it('adds no deduction component when nothing was broken that month', function () {
    $this->travelTo('2026-08-31 09:00:00');
    storeRules(lateLadderRules());

    $employee = Employee::factory()->active()->create();
    Attendance::factory()->create([
        'employee_id' => $employee->id,
        'date' => '2026-06-10',
        'check_in' => '08:00:00',
        'check_out' => '17:00:00',
        'status' => 'present',
    ]);

    $this->actingAs($this->admin)
        ->post(route('employees.salaries.store', $employee), [
            'period' => '2026-06',
            'components' => [['label' => 'Gaji Pokok', 'amount' => 5_000_000, 'type' => 'income']],
        ])
        ->assertRedirect();

    expect(Salary::sole()->components)->toHaveCount(1);
});

it('prices the payslip itself rather than trusting the form', function () {
    $this->travelTo('2026-08-31 09:00:00');
    storeRules(lateLadderRules());

    $employee = Employee::factory()->active()->create();
    Attendance::factory()->create([
        'employee_id' => $employee->id,
        'date' => '2026-06-10',
        'check_in' => '08:45:00',
        'check_out' => '17:00:00',
        'status' => 'late',
    ]);

    // The form claims a token 1.000 penalty; the record says 40.000.
    $this->actingAs($this->admin)
        ->post(route('employees.salaries.store', $employee), [
            'period' => '2026-06',
            'components' => [
                ['label' => 'Gaji Pokok', 'amount' => 5_000_000, 'type' => 'income'],
                ['label' => 'Potongan Keterlambatan', 'amount' => 1_000, 'type' => 'deduction'],
            ],
        ])
        ->assertRedirect();

    expect(Salary::sole()->deductions)->toBe(41_000);
});

// --- the employee page ---

it('exposes the monthly deduction on the employee page', function () {
    storeRules(lateLadderRules());

    $employee = Employee::factory()->active()->create();
    Attendance::factory()->create([
        'employee_id' => $employee->id,
        'date' => today(),
        'check_in' => '08:20:00',
        'check_out' => '17:00:00',
        'status' => 'late',
    ]);

    $this->actingAs($this->admin)
        ->get(route('employees.show', $employee))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('monthlyRecap.0.deduction', 15_000));
});
