<?php

use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Support\PayrollDeductionSettings;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

/** Submit every rule group in one payload (the real form does this). */
function deductionPayload(array $override = []): array
{
    return array_replace_recursive([
        'late' => ['enabled' => false, 'basis' => 'check_in', 'tiers' => []],
        'early_leave' => ['enabled' => false, 'tiers' => []],
        'break_overrun' => ['enabled' => false, 'tiers' => []],
        'absent' => ['enabled' => false, 'amount' => 0],
    ], $override);
}

/** A two-rung ladder: 15 minutes costs 15.000, half an hour costs 40.000. */
function lateLadder(): array
{
    return deductionPayload([
        'late' => [
            'enabled' => true,
            'basis' => 'check_in',
            'tiers' => [
                ['from_minutes' => 15, 'amount' => 15000],
                ['from_minutes' => 30, 'amount' => 40000],
            ],
        ],
    ]);
}

// --- screen ---

it('shows the deduction settings page with every rule off by default', function () {
    $this->get(route('payroll-deduction-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('payroll-deduction-settings/index')
            ->where('deductions.late.enabled', false)
            ->where('deductions.late.basis', PayrollDeductionSettings::BASIS_CHECK_IN)
            ->where('deductions.late.tiers', [])
            ->where('deductions.early_leave.enabled', false)
            ->where('deductions.break_overrun.enabled', false)
            ->where('deductions.absent.enabled', false)
            ->where('deductions.absent.amount', 0)
            ->has('officeHours.check_in')
            ->has('breakWindow.break_start')
            ->where('limits.max_tiers', PayrollDeductionSettings::MAX_TIERS)
        );
});

it('hides the screen entirely when the payroll feature is off', function () {
    Setting::set('payroll_enabled', false);

    $this->get(route('payroll-deduction-settings.index'))->assertNotFound();
    $this->put(route('payroll-deduction-settings.update'), deductionPayload())->assertNotFound();
});

it('only allows admins to manage deduction settings', function () {
    auth()->logout();
    $this->get(route('payroll-deduction-settings.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->get(route('payroll-deduction-settings.index'))
        ->assertForbidden();
});

// --- saving ---

it('saves a tiered late deduction ladder', function () {
    $this->put(route('payroll-deduction-settings.update'), lateLadder())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(PayrollDeductionSettings::globalRules()['late'])->toBe([
        'enabled' => true,
        'basis' => PayrollDeductionSettings::BASIS_CHECK_IN,
        'tiers' => [
            ['from_minutes' => 15, 'amount' => 15000],
            ['from_minutes' => 30, 'amount' => 40000],
        ],
    ]);
});

it('sorts the ladder by threshold no matter what order it arrives in', function () {
    $this->put(route('payroll-deduction-settings.update'), deductionPayload([
        'early_leave' => [
            'enabled' => true,
            'tiers' => [
                ['from_minutes' => 60, 'amount' => 50000],
                ['from_minutes' => 10, 'amount' => 10000],
                ['from_minutes' => 30, 'amount' => 25000],
            ],
        ],
    ]))->assertSessionHasNoErrors();

    expect(array_column(PayrollDeductionSettings::globalRules()['early_leave']['tiers'], 'from_minutes'))
        ->toBe([10, 30, 60]);
});

it('saves the late basis so tolerance can be excluded from the count', function () {
    $payload = lateLadder();
    $payload['late']['basis'] = PayrollDeductionSettings::BASIS_LATE_THRESHOLD;

    $this->put(route('payroll-deduction-settings.update'), $payload)
        ->assertSessionHasNoErrors();

    expect(PayrollDeductionSettings::globalRules()['late']['basis'])
        ->toBe(PayrollDeductionSettings::BASIS_LATE_THRESHOLD);
});

it('saves a break overrun ladder', function () {
    $this->put(route('payroll-deduction-settings.update'), deductionPayload([
        'break_overrun' => [
            'enabled' => true,
            'tiers' => [['from_minutes' => 10, 'amount' => 5000]],
        ],
    ]))->assertSessionHasNoErrors();

    expect(PayrollDeductionSettings::breakOverrunDeduction(PayrollDeductionSettings::globalRules(), 12))->toBe(5000);
});

it('saves a flat per-day absence deduction', function () {
    $this->put(route('payroll-deduction-settings.update'), deductionPayload([
        'absent' => ['enabled' => true, 'amount' => 100000],
    ]))->assertSessionHasNoErrors();

    expect(PayrollDeductionSettings::globalRules()['absent'])
        ->toBe(['enabled' => true, 'amount' => 100000]);
});

// --- validation ---

it('refuses an enabled rule with no tiers', function () {
    $this->put(route('payroll-deduction-settings.update'), deductionPayload([
        'late' => ['enabled' => true, 'basis' => 'check_in', 'tiers' => []],
    ]))->assertSessionHasErrors(['late.tiers']);
});

it('refuses two tiers starting at the same minute', function () {
    // The second rung would be unreachable: the deeper tier always wins.
    $this->put(route('payroll-deduction-settings.update'), deductionPayload([
        'late' => [
            'enabled' => true,
            'basis' => 'check_in',
            'tiers' => [
                ['from_minutes' => 15, 'amount' => 15000],
                ['from_minutes' => 15, 'amount' => 40000],
            ],
        ],
    ]))->assertSessionHasErrors(['late.tiers.1.from_minutes']);
});

it('validates tier fields', function (array $tier, array $errors) {
    $this->put(route('payroll-deduction-settings.update'), deductionPayload([
        'late' => ['enabled' => true, 'basis' => 'check_in', 'tiers' => [$tier]],
    ]))->assertSessionHasErrors($errors);
})->with([
    'zero minutes' => [['from_minutes' => 0, 'amount' => 15000], ['late.tiers.0.from_minutes']],
    'more than a day' => [['from_minutes' => 1441, 'amount' => 15000], ['late.tiers.0.from_minutes']],
    'non numeric minutes' => [['from_minutes' => 'lima', 'amount' => 15000], ['late.tiers.0.from_minutes']],
    'negative amount' => [['from_minutes' => 15, 'amount' => -1], ['late.tiers.0.amount']],
    'absurd amount' => [['from_minutes' => 15, 'amount' => 100_000_001], ['late.tiers.0.amount']],
    'missing amount' => [['from_minutes' => 15], ['late.tiers.0.amount']],
]);

it('refuses an unknown late basis', function () {
    $payload = lateLadder();
    $payload['late']['basis'] = 'sunrise';

    $this->put(route('payroll-deduction-settings.update'), $payload)
        ->assertSessionHasErrors(['late.basis']);
});

it('refuses a ladder deeper than the allowed number of tiers', function () {
    $tiers = [];

    for ($i = 1; $i <= PayrollDeductionSettings::MAX_TIERS + 1; $i++) {
        $tiers[] = ['from_minutes' => $i * 5, 'amount' => $i * 1000];
    }

    $this->put(route('payroll-deduction-settings.update'), deductionPayload([
        'late' => ['enabled' => true, 'basis' => 'check_in', 'tiers' => $tiers],
    ]))->assertSessionHasErrors(['late.tiers']);
});

it('refuses a negative absence amount', function () {
    $this->put(route('payroll-deduction-settings.update'), deductionPayload([
        'absent' => ['enabled' => true, 'amount' => -5000],
    ]))->assertSessionHasErrors(['absent.amount']);
});

it('allows an empty ladder while the rule is switched off', function () {
    $this->put(route('payroll-deduction-settings.update'), deductionPayload())
        ->assertSessionHasNoErrors();

    expect(PayrollDeductionSettings::anyEnabled(PayrollDeductionSettings::globalRules()))->toBeFalse();
});

// --- resolving an amount ---

it('deducts nothing at all until an admin configures something', function () {
    $rules = PayrollDeductionSettings::globalRules();

    expect(PayrollDeductionSettings::lateDeduction($rules, 120))->toBe(0)
        ->and(PayrollDeductionSettings::earlyLeaveDeduction($rules, 120))->toBe(0)
        ->and(PayrollDeductionSettings::breakOverrunDeduction($rules, 120))->toBe(0)
        ->and(PayrollDeductionSettings::absentDeduction($rules, 5))->toBe(0)
        ->and(PayrollDeductionSettings::anyEnabled($rules))->toBeFalse();
});

it('charges the deepest tier reached and never stacks them', function () {
    PayrollDeductionSettings::save(lateLadder());
    $rules = PayrollDeductionSettings::globalRules();

    expect(PayrollDeductionSettings::lateDeduction($rules, 0))->toBe(0)
        ->and(PayrollDeductionSettings::lateDeduction($rules, 14))->toBe(0)
        ->and(PayrollDeductionSettings::lateDeduction($rules, 15))->toBe(15000)
        ->and(PayrollDeductionSettings::lateDeduction($rules, 29))->toBe(15000)
        ->and(PayrollDeductionSettings::lateDeduction($rules, 30))->toBe(40000)
        // Not 55.000: the ladder selects one rung, it does not add them up.
        ->and(PayrollDeductionSettings::lateDeduction($rules, 240))->toBe(40000);
});

it('deducts nothing while a configured rule is switched off', function () {
    $payload = lateLadder();
    $payload['late']['enabled'] = false;

    PayrollDeductionSettings::save($payload);

    expect(PayrollDeductionSettings::lateDeduction(PayrollDeductionSettings::globalRules(), 45))->toBe(0)
        // The ladder survives being switched off, ready to be turned back on.
        ->and(PayrollDeductionSettings::globalRules()['late']['tiers'])->toHaveCount(2);
});

it('multiplies the absence deduction by the number of days', function () {
    PayrollDeductionSettings::save(deductionPayload([
        'absent' => ['enabled' => true, 'amount' => 100000],
    ]));

    $rules = PayrollDeductionSettings::globalRules();

    expect(PayrollDeductionSettings::absentDeduction($rules, 0))->toBe(0)
        ->and(PayrollDeductionSettings::absentDeduction($rules, 1))->toBe(100000)
        ->and(PayrollDeductionSettings::absentDeduction($rules, 3))->toBe(300000);
});

it('reports that a rule is active as soon as any group is enabled', function () {
    PayrollDeductionSettings::save(deductionPayload([
        'absent' => ['enabled' => true, 'amount' => 50000],
    ]));

    expect(PayrollDeductionSettings::anyEnabled(PayrollDeductionSettings::globalRules()))->toBeTrue();
});

it('drops unreachable duplicate rungs written straight into the settings row', function () {
    // Validation blocks this on the way in; a hand-edited row must not be able
    // to smuggle a rung that could never fire.
    Setting::set(PayrollDeductionSettings::KEY, [
        'late' => [
            'enabled' => true,
            'basis' => 'check_in',
            'tiers' => [
                ['from_minutes' => 15, 'amount' => 15000],
                ['from_minutes' => 15, 'amount' => 99000],
            ],
        ],
    ]);

    expect(PayrollDeductionSettings::globalRules()['late']['tiers'])
        ->toBe([['from_minutes' => 15, 'amount' => 15000]]);
});

it('falls back to defaults for groups missing from a stored row', function () {
    Setting::set(PayrollDeductionSettings::KEY, [
        'late' => ['enabled' => true, 'tiers' => [['from_minutes' => 20, 'amount' => 20000]]],
    ]);

    $settings = PayrollDeductionSettings::globalRules();

    expect($settings['late']['basis'])->toBe(PayrollDeductionSettings::BASIS_CHECK_IN)
        ->and($settings['early_leave'])->toBe(['enabled' => false, 'tiers' => []])
        ->and($settings['absent'])->toBe(['enabled' => false, 'amount' => 0]);
});

// --- per-shift overrides ---

/** Shift mode on, one shift, and an employee assigned to it. */
function shiftWithEmployee(array $shiftAttributes = []): array
{
    Setting::set('attendance_shift_enabled', true);

    $shift = Shift::factory()->create($shiftAttributes);
    $employee = Employee::factory()->active()->create(['shift_id' => $shift->id]);

    return [$shift, $employee];
}

it('lists the shifts alongside the global rules', function () {
    [$shift] = shiftWithEmployee(['name' => 'Malam', 'check_in' => '22:00:00']);

    $this->get(route('payroll-deduction-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('shiftMode', true)
            ->has('shifts', 1)
            ->where('shifts.0.name', 'Malam')
            ->where('shifts.0.check_in', '22:00')
            ->where('shifts.0.overrides', false)
            ->where('shifts.0.employees_count', 1)
        );
});

it('offers no shift panels while shift mode is off', function () {
    Shift::factory()->create();

    $this->get(route('payroll-deduction-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('shiftMode', false)
            ->where('shifts', [])
        );
});

it('gives a shift its own ladder', function () {
    [$shift] = shiftWithEmployee();

    $payload = array_merge(lateLadder(), ['overrides' => true]);
    $payload['late']['tiers'] = [['from_minutes' => 5, 'amount' => 50000]];

    $this->put(route('payroll-deduction-settings.shifts.update', $shift), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $shift->refresh();

    expect($shift->hasOwnDeductionRules())->toBeTrue()
        ->and($shift->deductionRules()['late']['tiers'])
        ->toBe([['from_minutes' => 5, 'amount' => 50000]]);
});

it('drops the override so the shift follows the global rules again', function () {
    [$shift] = shiftWithEmployee();

    $shift->update(['deduction_rules' => PayrollDeductionSettings::normalize(lateLadder())]);
    expect($shift->fresh()->hasOwnDeductionRules())->toBeTrue();

    // A shift that follows the global rules submits nothing but the flag.
    $this->put(route('payroll-deduction-settings.shifts.update', $shift), ['overrides' => false])
        ->assertSessionHasNoErrors();

    expect($shift->fresh()->hasOwnDeductionRules())->toBeFalse();
});

it('validates a shift ladder the same way as the global one', function () {
    [$shift] = shiftWithEmployee();

    $payload = array_merge(lateLadder(), ['overrides' => true]);
    $payload['late']['tiers'] = [
        ['from_minutes' => 15, 'amount' => 15000],
        ['from_minutes' => 15, 'amount' => 40000],
    ];

    $this->put(route('payroll-deduction-settings.shifts.update', $shift), $payload)
        ->assertSessionHasErrors(['late.tiers.1.from_minutes']);

    expect($shift->fresh()->hasOwnDeductionRules())->toBeFalse();
});

it('hides the shift endpoint when the payroll feature is off', function () {
    [$shift] = shiftWithEmployee();
    Setting::set('payroll_enabled', false);

    $this->put(route('payroll-deduction-settings.shifts.update', $shift), ['overrides' => false])
        ->assertNotFound();
});

// --- which rules apply to whom ---

it('applies the shift ladder to an employee on that shift', function () {
    [$shift, $employee] = shiftWithEmployee();

    PayrollDeductionSettings::save(lateLadder());
    $shift->update(['deduction_rules' => PayrollDeductionSettings::normalize(deductionPayload([
        'late' => [
            'enabled' => true,
            'basis' => 'check_in',
            'tiers' => [['from_minutes' => 5, 'amount' => 50000]],
        ],
    ]))]);

    $rules = PayrollDeductionSettings::forEmployee($employee, today());

    // 10 minutes late: 50.000 under the shift ladder, nothing under the global
    // one, whose first rung only starts at 15.
    expect(PayrollDeductionSettings::lateDeduction($rules, 10))->toBe(50000);
});

it('falls back to the global ladder for a shift that does not override', function () {
    [, $employee] = shiftWithEmployee();

    PayrollDeductionSettings::save(lateLadder());

    $rules = PayrollDeductionSettings::forEmployee($employee, today());

    expect(PayrollDeductionSettings::lateDeduction($rules, 20))->toBe(15000);
});

it('falls back to the global ladder for an employee with no shift', function () {
    Setting::set('attendance_shift_enabled', true);
    Shift::factory()->create(['deduction_rules' => PayrollDeductionSettings::normalize(deductionPayload([
        'late' => ['enabled' => true, 'basis' => 'check_in', 'tiers' => [['from_minutes' => 1, 'amount' => 99000]]],
    ]))]);

    $employee = Employee::factory()->active()->create(['shift_id' => null]);
    PayrollDeductionSettings::save(lateLadder());

    $rules = PayrollDeductionSettings::forEmployee($employee, today());

    expect(PayrollDeductionSettings::lateDeduction($rules, 20))->toBe(15000);
});

it('ignores a shift override entirely while shift mode is off', function () {
    [$shift, $employee] = shiftWithEmployee();

    $shift->update(['deduction_rules' => PayrollDeductionSettings::normalize(deductionPayload([
        'late' => ['enabled' => true, 'basis' => 'check_in', 'tiers' => [['from_minutes' => 1, 'amount' => 99000]]],
    ]))]);
    PayrollDeductionSettings::save(lateLadder());

    Setting::set('attendance_shift_enabled', false);

    $rules = PayrollDeductionSettings::forEmployee($employee, today());

    expect(PayrollDeductionSettings::lateDeduction($rules, 20))->toBe(15000);
});

it('prefers the per-date shift ladder over the default shift', function () {
    [$defaultShift, $employee] = shiftWithEmployee();

    $defaultShift->update(['deduction_rules' => PayrollDeductionSettings::normalize(deductionPayload([
        'late' => ['enabled' => true, 'basis' => 'check_in', 'tiers' => [['from_minutes' => 1, 'amount' => 10000]]],
    ]))]);

    $eveningShift = Shift::factory()->create([
        'deduction_rules' => PayrollDeductionSettings::normalize(deductionPayload([
            'late' => ['enabled' => true, 'basis' => 'check_in', 'tiers' => [['from_minutes' => 1, 'amount' => 77000]]],
        ])),
    ]);
    EmployeeSchedule::factory()->create([
        'employee_id' => $employee->id,
        'shift_id' => $eveningShift->id,
        'date' => today()->toDateString(),
    ]);

    $rules = PayrollDeductionSettings::forEmployee($employee, today());

    expect(PayrollDeductionSettings::lateDeduction($rules, 5))->toBe(77000);
});
