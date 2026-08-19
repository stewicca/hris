<?php

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

test('attendance index page is accessible', function () {
    $this->get(route('attendance.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('attendance/index')
            ->has('records')
            ->missing('summary')
            ->has('departments')
            ->has('filters')
        );
});

test('attendance index redirects guests', function () {
    auth()->logout();

    $this->get(route('attendance.index'))->assertRedirect(route('login'));
});

test('attendance index shows only active employees', function () {
    Employee::factory()->create(['status' => 'active']);
    Employee::factory()->create(['status' => 'inactive']);

    $this->get(route('attendance.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('records', 1));
});

test('attendance index filters records by status', function () {
    $date = today();

    $e1 = Employee::factory()->create(['status' => 'active']);
    $e2 = Employee::factory()->create(['status' => 'active']);
    Attendance::factory()->create(['employee_id' => $e1->id, 'date' => $date, 'status' => 'present']);
    Attendance::factory()->create(['employee_id' => $e2->id, 'date' => $date, 'status' => 'late']);

    $this->get(route('attendance.index', ['status' => 'present']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('records', 1));
});

test('attendance index filters records by department', function () {
    $engineering = Department::factory()->create(['name' => 'Engineering']);
    $hr = Department::factory()->create(['name' => 'HR']);
    Employee::factory()->create(['status' => 'active', 'department_id' => $engineering->id]);
    Employee::factory()->create(['status' => 'active', 'department_id' => $hr->id]);

    $this->get(route('attendance.index', ['department' => 'Engineering']))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('records', 1));
});

test('attendance index can query a specific date', function () {
    $yesterday = today()->subDay();
    $employee = Employee::factory()->create(['status' => 'active']);
    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => $yesterday, 'status' => 'present']);

    $this->get(route('attendance.index', ['date' => $yesterday->toDateString()]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('records.0.status', 'present')
            ->where('filters.date', $yesterday->toDateString())
        );
});
