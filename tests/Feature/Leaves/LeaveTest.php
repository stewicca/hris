<?php

use App\Models\Employee;
use App\Models\Leave;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->actingAs(User::factory()->admin()->create());
});

// Index
test('leave index page is accessible', function () {
    $this->get(route('leaves.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('leaves/index')
            ->has('leaves')
            ->has('employees')
            ->has('filters')
        );
});

test('leave index filters by status', function () {
    $employee = Employee::factory()->create();
    Leave::factory()->create(['employee_id' => $employee->id, 'status' => 'pending']);
    Leave::factory()->create(['employee_id' => $employee->id, 'status' => 'approved']);

    $this->get(route('leaves.index', ['status' => 'pending']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('leaves.data', 1));
});

test('leave index filters by type', function () {
    $employee = Employee::factory()->create();
    Leave::factory()->create(['employee_id' => $employee->id, 'type' => 'annual']);
    Leave::factory()->create(['employee_id' => $employee->id, 'type' => 'sick']);

    $this->get(route('leaves.index', ['type' => 'sick']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('leaves.data', 1));
});

// Create
test('leave create page is accessible', function () {
    $this->get(route('leaves.create'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('leaves/create')
            ->has('employees')
        );
});

// Store
test('leave can be created with valid data', function () {
    $employee = Employee::factory()->create();

    $this->post(route('leaves.store'), [
        'employee_id' => $employee->id,
        'type' => 'annual',
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'reason' => 'Keperluan keluarga',
    ])->assertRedirect(route('leaves.index'));

    expect(Leave::where('employee_id', $employee->id)->exists())->toBeTrue();
});

test('leave store calculates days correctly', function () {
    $employee = Employee::factory()->create();

    $this->post(route('leaves.store'), [
        'employee_id' => $employee->id,
        'type' => 'sick',
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-14',
        'reason' => 'Sakit',
    ]);

    expect(Leave::where('employee_id', $employee->id)->first()->days)->toBe(5);
});

test('leave store validates required fields', function () {
    $this->post(route('leaves.store'), [])
        ->assertSessionHasErrors(['employee_id', 'type', 'start_date', 'end_date', 'reason']);
});

test('leave store rejects end date before start date', function () {
    $employee = Employee::factory()->create();

    $this->post(route('leaves.store'), [
        'employee_id' => $employee->id,
        'type' => 'annual',
        'start_date' => '2026-06-14',
        'end_date' => '2026-06-10',
        'reason' => 'Test',
    ])->assertSessionHasErrors('end_date');
});

// Show
test('leave show page renders detail', function () {
    $leave = Leave::factory()->create();

    $this->get(route('leaves.show', $leave))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('leaves/show')
            ->has('leave')
            ->where('leave.id', $leave->id)
        );
});

// Approve
test('pending leave can be approved', function () {
    $leave = Leave::factory()->create(['status' => 'pending']);

    $this->post(route('leaves.approve', $leave))->assertRedirect();

    expect($leave->fresh()->status)->toBe('approved');
    expect($leave->fresh()->approved_by)->not->toBeNull();
});

test('approved leave cannot be approved again', function () {
    $leave = Leave::factory()->approved()->create();

    $this->post(route('leaves.approve', $leave))->assertForbidden();
});

// Reject
test('pending leave can be rejected with reason', function () {
    $leave = Leave::factory()->create(['status' => 'pending']);

    $this->post(route('leaves.reject', $leave), [
        'rejection_reason' => 'Tidak ada pengganti',
    ])->assertRedirect();

    expect($leave->fresh()->status)->toBe('rejected');
    expect($leave->fresh()->rejection_reason)->toBe('Tidak ada pengganti');
});

test('reject requires a reason', function () {
    $leave = Leave::factory()->create(['status' => 'pending']);

    $this->post(route('leaves.reject', $leave), [])
        ->assertSessionHasErrors('rejection_reason');
});

// Destroy
test('pending leave can be cancelled', function () {
    $leave = Leave::factory()->create(['status' => 'pending']);

    $this->delete(route('leaves.destroy', $leave))
        ->assertRedirect(route('leaves.index'));

    expect(Leave::find($leave->id))->toBeNull();
});

test('approved leave cannot be cancelled', function () {
    $leave = Leave::factory()->approved()->create();

    $this->delete(route('leaves.destroy', $leave))->assertForbidden();
});

// Annual leave quota
test('annual leave store is rejected when it exceeds the remaining quota', function () {
    $year = now()->year;
    $employee = Employee::factory()->create(['annual_leave_quota' => 5]);
    Leave::factory()->approved()->create([
        'employee_id' => $employee->id,
        'type' => 'annual',
        'start_date' => "{$year}-02-01",
        'end_date' => "{$year}-02-03",
        'days' => 3,
    ]);

    $this->post(route('leaves.store'), [
        'employee_id' => $employee->id,
        'type' => 'annual',
        'start_date' => "{$year}-03-01",
        'end_date' => "{$year}-03-03", // 3 days, only 2 remaining
        'reason' => 'Liburan',
    ])->assertSessionHasErrors('end_date');

    expect(Leave::where('employee_id', $employee->id)->count())->toBe(1);
});

test('annual leave store succeeds within the remaining quota', function () {
    $year = now()->year;
    $employee = Employee::factory()->create(['annual_leave_quota' => 5]);

    $this->post(route('leaves.store'), [
        'employee_id' => $employee->id,
        'type' => 'annual',
        'start_date' => "{$year}-03-01",
        'end_date' => "{$year}-03-04", // 4 days
        'reason' => 'Liburan',
    ])->assertRedirect(route('leaves.index'));

    expect(Leave::where('employee_id', $employee->id)->count())->toBe(1);
});

test('sick leave is not limited by the annual quota', function () {
    $employee = Employee::factory()->create(['annual_leave_quota' => 0]);

    $this->post(route('leaves.store'), [
        'employee_id' => $employee->id,
        'type' => 'sick',
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-10',
        'reason' => 'Opname',
    ])->assertRedirect(route('leaves.index'));

    expect(Leave::where('employee_id', $employee->id)->count())->toBe(1);
});

// Feature toggle

test('leave routes are hidden when the feature is disabled', function () {
    Setting::set('leave_enabled', false);
    $employee = Employee::factory()->create();

    $this->get(route('leaves.index'))->assertNotFound();
    $this->get(route('leaves.create'))->assertNotFound();

    $this->post(route('leaves.store'), [
        'employee_id' => $employee->id,
        'type' => 'sick',
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-02',
        'reason' => 'Sakit',
    ])->assertNotFound();

    expect(Leave::where('employee_id', $employee->id)->count())->toBe(0);
});
