<?php

use App\Models\Employee;
use App\Models\Salary;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
});

it('returns the employee salaries with derived totals, newest first', function () {
    Salary::factory()->create([
        'employee_id' => $this->employee->id,
        'period' => '2026-05-01',
        'components' => [
            ['label' => 'Gaji Pokok', 'amount' => 5_000_000, 'type' => 'income'],
            ['label' => 'PPh 21', 'amount' => 250_000, 'type' => 'deduction'],
        ],
    ]);
    Salary::factory()->paid()->create([
        'employee_id' => $this->employee->id,
        'period' => '2026-04-01',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/salary')
        ->assertOk();

    expect($response->json('salaries'))->toHaveCount(2);

    $response
        ->assertJsonPath('salaries.0.period', 'Mei 2026')
        ->assertJsonPath('salaries.0.gross', 5_000_000)
        ->assertJsonPath('salaries.0.deductions', 250_000)
        ->assertJsonPath('salaries.0.net', 4_750_000)
        ->assertJsonPath('salaries.0.status', 'pending')
        ->assertJsonPath('salaries.1.status', 'paid');
});

it('returns 404 when the user has no employee profile', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/salary')
        ->assertNotFound();
});

it('requires authentication', function () {
    $this->getJson('/api/salary')->assertUnauthorized();
});

it('only returns the salaries belonging to the authenticated employee', function () {
    Salary::factory()->create(['employee_id' => $this->employee->id]);
    Salary::factory()->create(); // another employee

    $response = $this->actingAs($this->user)
        ->getJson('/api/salary')
        ->assertOk();

    expect($response->json('salaries'))->toHaveCount(1);
});

it('renders a printable payslip for the owning employee', function () {
    $salary = Salary::factory()->create([
        'employee_id' => $this->employee->id,
        'period' => '2026-05-01',
        'components' => [['label' => 'Gaji Pokok', 'amount' => 5_000_000, 'type' => 'income']],
    ]);

    $this->actingAs($this->user)
        ->get("/api/salary/{$salary->id}/print")
        ->assertOk()
        ->assertSee('Slip Gaji')
        ->assertSee('Mei 2026')
        ->assertSee('Rp 5.000.000');
});

it('forbids printing another employee salary', function () {
    $salary = Salary::factory()->create();

    $this->actingAs($this->user)
        ->get("/api/salary/{$salary->id}/print")
        ->assertForbidden();
});
