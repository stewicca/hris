<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->actingAs(User::factory()->admin()->create());
});

test('master data index lists departments and positions', function () {
    Department::factory()->create();
    Position::factory()->create();

    $this->get(route('master-data.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('master-data/index')
            ->has('departments', 1)
            ->has('positions', 1)
        );
});

test('admin can add a department', function () {
    $this->post(route('master-data.departments.store'), ['name' => 'Engineering'])
        ->assertRedirect();

    expect(Department::where('name', 'Engineering')->exists())->toBeTrue();
});

test('department name must be unique', function () {
    Department::factory()->create(['name' => 'Engineering']);

    $this->post(route('master-data.departments.store'), ['name' => 'Engineering'])
        ->assertSessionHasErrors('name');

    expect(Department::where('name', 'Engineering')->count())->toBe(1);
});

test('deleting a department unlinks its employees', function () {
    $department = Department::factory()->create();
    $employee = Employee::factory()->create(['department_id' => $department->id]);

    $this->delete(route('master-data.departments.destroy', $department))
        ->assertRedirect();

    expect(Department::find($department->id))->toBeNull();
    expect($employee->fresh()->department_id)->toBeNull();
});

test('admin can add and delete a position', function () {
    $this->post(route('master-data.positions.store'), ['name' => 'Manager'])
        ->assertRedirect();

    $position = Position::where('name', 'Manager')->sole();

    $this->delete(route('master-data.positions.destroy', $position))
        ->assertRedirect();

    expect(Position::find($position->id))->toBeNull();
});

test('non-admins cannot manage master data', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('master-data.departments.store'), ['name' => 'X'])
        ->assertForbidden();
});
