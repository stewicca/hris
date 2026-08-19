<?php

use App\Models\Employee;
use App\Models\Leave;
use App\Models\User;
use App\Notifications\EmployeeNotification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
});

it('lists the user notifications with an unread count', function () {
    $this->user->notify(new EmployeeNotification('Hello', 'World', 'info'));

    $this->actingAs($this->user)
        ->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonPath('unread', 1)
        ->assertJsonPath('notifications.0.title', 'Hello')
        ->assertJsonPath('notifications.0.read', false);
});

it('marks a single notification as read', function () {
    $this->user->notify(new EmployeeNotification('Hello', 'World'));
    $id = $this->user->notifications()->sole()->id;

    $this->actingAs($this->user)
        ->postJson("/api/notifications/{$id}/read")
        ->assertNoContent();

    expect($this->user->unreadNotifications()->count())->toBe(0);
});

it('marks all notifications as read', function () {
    $this->user->notify(new EmployeeNotification('A', 'a'));
    $this->user->notify(new EmployeeNotification('B', 'b'));

    $this->actingAs($this->user)
        ->postJson('/api/notifications/read-all')
        ->assertNoContent();

    expect($this->user->unreadNotifications()->count())->toBe(0);
});

it('notifies the employee when their leave is approved', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $leave = Leave::factory()->create(['employee_id' => $this->employee->id, 'status' => 'pending']);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('leaves.approve', $leave))
        ->assertRedirect();

    expect($this->user->notifications()->count())->toBe(1)
        ->and($this->user->notifications()->sole()->data['type'])->toBe('leave');
});

it('notifies the employee when a payslip is created', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('employees.salaries.store', $this->employee), [
            'period' => '2026-06',
            'components' => [['label' => 'Gaji Pokok', 'amount' => 5_000_000, 'type' => 'income']],
        ])
        ->assertRedirect();

    expect($this->user->notifications()->sole()->data['type'])->toBe('salary');
});

it('requires authentication to list notifications', function () {
    $this->getJson('/api/notifications')->assertUnauthorized();
});
