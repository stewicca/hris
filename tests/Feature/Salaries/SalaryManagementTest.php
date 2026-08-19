<?php

use App\Models\Employee;
use App\Models\Salary;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->employee = Employee::factory()->create();
});

function payslipPayload(array $override = []): array
{
    return array_merge([
        'period' => '2026-06',
        'components' => [
            ['label' => 'Gaji Pokok', 'amount' => 5_000_000, 'type' => 'income'],
            ['label' => 'PPh 21', 'amount' => 250_000, 'type' => 'deduction'],
        ],
    ], $override);
}

it('lets an admin create a payslip for an employee', function () {
    $this->actingAs($this->admin)
        ->post(route('employees.salaries.store', $this->employee), payslipPayload())
        ->assertRedirect();

    $salary = Salary::sole();

    expect($salary->employee_id)->toBe($this->employee->id)
        ->and($salary->status)->toBe('pending')
        ->and($salary->period->format('Y-m'))->toBe('2026-06')
        ->and($salary->net)->toBe(4_750_000);
});

it('rejects a duplicate period for the same employee', function () {
    Salary::factory()->create([
        'employee_id' => $this->employee->id,
        'period' => '2026-06-01',
    ]);

    $this->actingAs($this->admin)
        ->post(route('employees.salaries.store', $this->employee), payslipPayload())
        ->assertSessionHasErrors('period');

    expect(Salary::where('employee_id', $this->employee->id)->count())->toBe(1);
});

it('validates the components payload', function () {
    $this->actingAs($this->admin)
        ->post(route('employees.salaries.store', $this->employee), payslipPayload([
            'components' => [['label' => '', 'amount' => -5, 'type' => 'bogus']],
        ]))
        ->assertSessionHasErrors([
            'components.0.label',
            'components.0.amount',
            'components.0.type',
        ]);
});

it('lets an admin mark a payslip as paid', function () {
    $salary = Salary::factory()->create(['employee_id' => $this->employee->id]);

    $this->actingAs($this->admin)
        ->post(route('salaries.mark-paid', $salary))
        ->assertRedirect();

    expect($salary->fresh())
        ->status->toBe('paid')
        ->paid_at->not->toBeNull();
});

it('lets an admin delete a payslip', function () {
    $salary = Salary::factory()->create(['employee_id' => $this->employee->id]);

    $this->actingAs($this->admin)
        ->delete(route('salaries.destroy', $salary))
        ->assertRedirect();

    expect(Salary::find($salary->id))->toBeNull();
});

it('renders a printable payslip for an admin', function () {
    $salary = Salary::factory()->create([
        'employee_id' => $this->employee->id,
        'period' => '2026-06-01',
        'components' => [['label' => 'Gaji Pokok', 'amount' => 5_000_000, 'type' => 'income']],
    ]);

    $this->actingAs($this->admin)
        ->get(route('salaries.print', $salary))
        ->assertOk()
        ->assertSee($this->employee->name)
        ->assertSee('Juni 2026')
        ->assertSee('Rp 5.000.000');
});

it('forbids non-admins from printing a payslip', function () {
    $salary = Salary::factory()->create(['employee_id' => $this->employee->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('salaries.print', $salary))
        ->assertForbidden();
});

it('forbids non-admins from managing salaries', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('employees.salaries.store', $this->employee), payslipPayload())
        ->assertForbidden();
});
