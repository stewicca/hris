<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('summary')
            ->has('date')
            ->has('leaveStats')
        );
});

test('dashboard summary counts today attendance correctly', function () {
    Setting::set('working_days', [1, 2, 3, 4, 5, 6, 7]); // make today always a working day
    $this->actingAs(User::factory()->admin()->create());

    $presentEmployee = Employee::factory()->create(['status' => 'active']);
    $lateEmployee = Employee::factory()->create(['status' => 'active']);
    Employee::factory()->create(['status' => 'active']);

    Attendance::factory()->create(['employee_id' => $presentEmployee->id, 'date' => today(), 'status' => 'present']);
    Attendance::factory()->create(['employee_id' => $lateEmployee->id, 'date' => today(), 'status' => 'late']);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.present', 1)
            ->where('summary.late', 1)
            ->where('summary.absent', 1)
            ->where('summary.total', 3)
        );
});

test('dashboard does not count absences on a non-working day', function () {
    Setting::set('working_days', []); // today is never a working day
    $this->actingAs(User::factory()->admin()->create());

    Employee::factory()->create(['status' => 'active']);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.absent', 0)
            ->where('summary.is_working_day', false)
        );
});

test('dashboard counts approved leave as leave, not absent', function () {
    Setting::set('working_days', [1, 2, 3, 4, 5, 6, 7]);
    $this->actingAs(User::factory()->admin()->create());

    $employee = Employee::factory()->create(['status' => 'active']);
    Leave::factory()->approved()->create([
        'employee_id' => $employee->id,
        'start_date' => today(),
        'end_date' => today(),
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.absent', 0)
            ->where('summary.leave', 1)
        );
});

test('dashboard leave stats show pending count', function () {
    $this->actingAs(User::factory()->admin()->create());

    $employee = Employee::factory()->create();
    Leave::factory()->create(['employee_id' => $employee->id, 'status' => 'pending']);
    Leave::factory()->create(['employee_id' => $employee->id, 'status' => 'pending']);
    Leave::factory()->approved()->create(['employee_id' => $employee->id]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('leaveStats.pending', 2));
});

test('dashboard leave stats show employees on leave today', function () {
    $this->actingAs(User::factory()->admin()->create());

    $employee = Employee::factory()->create();
    Leave::factory()->approved()->create([
        'employee_id' => $employee->id,
        'start_date' => today()->subDay(),
        'end_date' => today()->addDay(),
    ]);
    Leave::factory()->approved()->create([
        'employee_id' => $employee->id,
        'start_date' => today()->addDays(5),
        'end_date' => today()->addDays(7),
    ]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('leaveStats.on_leave_today', 1));
});

test('dashboard summary only includes active employees', function () {
    $this->actingAs(User::factory()->admin()->create());

    Employee::factory()->create(['status' => 'active']);
    Employee::factory()->create(['status' => 'inactive']);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary.total', 1));
});
