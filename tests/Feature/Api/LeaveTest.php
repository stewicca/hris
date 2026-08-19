<?php

use App\Models\Employee;
use App\Models\Leave;
use App\Models\Setting;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->employee = Employee::factory()->create([
        'user_id' => $this->user->id,
        'annual_leave_quota' => 12,
    ]);
});

it('returns the leaves and annual quota summary', function () {
    $year = now()->year;
    Leave::factory()->approved()->create([
        'employee_id' => $this->employee->id,
        'type' => 'annual',
        'start_date' => "{$year}-02-01",
        'end_date' => "{$year}-02-04",
        'days' => 4,
    ]);
    Leave::factory()->create([
        'employee_id' => $this->employee->id,
        'type' => 'annual',
        'start_date' => "{$year}-03-01",
        'end_date' => "{$year}-03-02",
        'days' => 2,
        'status' => 'pending',
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/leaves')
        ->assertOk()
        ->assertJsonPath('quota.quota', 12)
        ->assertJsonPath('quota.used', 4)
        ->assertJsonPath('quota.pending', 2)
        ->assertJsonPath('quota.remaining', 6);
});

it('lets an employee submit an annual leave within quota', function () {
    $year = now()->year;

    $this->actingAs($this->user)
        ->postJson('/api/leaves', [
            'type' => 'annual',
            'start_date' => "{$year}-04-01",
            'end_date' => "{$year}-04-03",
            'reason' => 'Liburan keluarga',
        ])
        ->assertCreated();

    expect($this->employee->leaves()->count())->toBe(1);
});

it('rejects an annual leave that exceeds the remaining quota', function () {
    $year = now()->year;
    $this->employee->update(['annual_leave_quota' => 2]);

    $this->actingAs($this->user)
        ->postJson('/api/leaves', [
            'type' => 'annual',
            'start_date' => "{$year}-04-01",
            'end_date' => "{$year}-04-05", // 5 days, quota is 2
            'reason' => 'Liburan panjang',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('end_date');

    expect($this->employee->leaves()->count())->toBe(0);
});

it('does not limit sick leave by the annual quota', function () {
    $year = now()->year;
    $this->employee->update(['annual_leave_quota' => 0]);

    $this->actingAs($this->user)
        ->postJson('/api/leaves', [
            'type' => 'sick',
            'start_date' => "{$year}-04-01",
            'end_date' => "{$year}-04-10",
            'reason' => 'Sakit',
        ])
        ->assertCreated();

    expect($this->employee->leaves()->count())->toBe(1);
});

it('requires authentication', function () {
    $this->getJson('/api/leaves')->assertUnauthorized();
});

it('returns 404 when the user has no employee profile', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/leaves')
        ->assertNotFound();
});

it('blocks leave endpoints when the feature is disabled', function () {
    Setting::set('leave_enabled', false);

    $this->actingAs($this->user)
        ->getJson('/api/leaves')
        ->assertNotFound();

    $year = now()->year;
    $this->actingAs($this->user)
        ->postJson('/api/leaves', [
            'type' => 'annual',
            'start_date' => "{$year}-04-01",
            'end_date' => "{$year}-04-03",
            'reason' => 'Liburan',
        ])
        ->assertNotFound();

    expect($this->employee->leaves()->count())->toBe(0);
});

it('exposes the leave feature flag via attendance settings', function () {
    $this->actingAs($this->user)
        ->getJson('/api/attendance/settings')
        ->assertOk()
        ->assertJsonPath('leave_enabled', true);

    Setting::set('leave_enabled', false);

    $this->actingAs($this->user)
        ->getJson('/api/attendance/settings')
        ->assertOk()
        ->assertJsonPath('leave_enabled', false);
});
