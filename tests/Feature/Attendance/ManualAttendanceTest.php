<?php

use App\AttendanceEventType;
use App\Models\Attendance;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\EmployeeNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->employee = Employee::factory()->active()->create();
});

/**
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function manualPayload(Employee $employee, array $override = []): array
{
    return [
        'employee_id' => $employee->id,
        'date' => today()->toDateString(),
        'status' => 'present',
        'check_in' => '08:00',
        ...$override,
    ];
}

// --- marking a day nobody worked ---

it('marks an employee sick without any times', function () {
    $this->post(route('attendance.store'), [
        'employee_id' => $this->employee->id,
        'date' => today()->toDateString(),
        'status' => 'sick',
        'notes' => 'Demam, ada surat dokter',
    ])->assertRedirect();

    $attendance = Attendance::firstWhere('employee_id', $this->employee->id);

    expect($attendance->status)->toBe('sick')
        ->and($attendance->check_in)->toBeNull()
        ->and($attendance->check_out)->toBeNull()
        ->and($attendance->notes)->toBe('Demam, ada surat dokter')
        ->and($attendance->recorded_by)->toBe($this->admin->id)
        ->and($attendance->events)->toHaveCount(0);
});

it('marks an employee as excused with each non-working status', function (string $status) {
    $this->post(route('attendance.store'), [
        'employee_id' => $this->employee->id,
        'date' => today()->toDateString(),
        'status' => $status,
        'notes' => 'Keterangan',
    ])->assertRedirect();

    expect(Attendance::firstWhere('employee_id', $this->employee->id)->status)->toBe($status);
})->with(['sick', 'permit', 'absent']);

it('requires a reason before recording sick or permit', function (string $status) {
    $this->post(route('attendance.store'), [
        'employee_id' => $this->employee->id,
        'date' => today()->toDateString(),
        'status' => $status,
    ])->assertSessionHasErrors(['notes']);

    expect(Attendance::count())->toBe(0);
})->with(['sick', 'permit']);

it('records a plain absence without demanding a reason', function () {
    $this->post(route('attendance.store'), [
        'employee_id' => $this->employee->id,
        'date' => today()->toDateString(),
        'status' => 'absent',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Attendance::firstWhere('employee_id', $this->employee->id)->notes)->toBeNull();
});

// --- filling in a forgotten day ---

it('fills a whole day and derives present from the check-in time', function () {
    $this->post(route('attendance.store'), manualPayload($this->employee, [
        'check_in' => '07:55',
        'check_out' => '17:05',
    ]))->assertRedirect();

    $attendance = Attendance::firstWhere('employee_id', $this->employee->id);

    expect($attendance->status)->toBe('present')
        ->and($attendance->check_in)->toBe('07:55:00')
        ->and($attendance->check_out)->toBe('17:05:00');
});

it('derives late from the check-in time rather than trusting the admin', function () {
    // Global office hours put the late threshold at 08:05.
    $this->post(route('attendance.store'), manualPayload($this->employee, [
        'check_in' => '09:30',
    ]))->assertRedirect();

    expect(Attendance::firstWhere('employee_id', $this->employee->id)->status)->toBe('late');
});

it('writes an audit event for every filled time, marked as admin-recorded', function () {
    Setting::set('attendance_break_enabled', true);

    $this->post(route('attendance.store'), manualPayload($this->employee, [
        'check_in' => '08:00',
        'break_start' => '12:00',
        'break_end' => '13:00',
        'check_out' => '17:00',
    ]))->assertRedirect();

    $events = Attendance::firstWhere('employee_id', $this->employee->id)->events;

    expect($events)->toHaveCount(4);

    foreach ($events as $event) {
        expect($event->recorded_by)->toBe($this->admin->id)
            ->and($event->lat)->toBeNull()
            ->and($event->photo_path)->toBeNull()
            ->and($event->face_verified)->toBeFalse();
    }
});

it('leaves a clocked check-in alone when only the check-out is added', function () {
    $attendance = Attendance::factory()->for($this->employee)->create([
        'date' => today(),
        'check_in' => '08:00:00',
        'check_out' => null,
        'status' => 'present',
    ]);

    $clocked = AttendanceEvent::factory()->create([
        'attendance_id' => $attendance->id,
        'type' => AttendanceEventType::CheckIn,
        'occurred_at' => today()->setTime(8, 0),
        'lat' => -6.2,
        'lng' => 106.8,
        'photo_path' => 'attendance-photos/selfie.jpg',
        'face_verified' => true,
    ]);

    $this->post(route('attendance.store'), manualPayload($this->employee, [
        'check_in' => '08:00',
        'check_out' => '17:00',
    ]))->assertRedirect();

    $clocked->refresh();

    // The employee's own evidence survives untouched; only the added time is
    // stamped with the admin's id.
    expect($clocked->recorded_by)->toBeNull()
        ->and($clocked->lat)->toBe(-6.2)
        ->and($clocked->photo_path)->toBe('attendance-photos/selfie.jpg')
        ->and($clocked->face_verified)->toBeTrue()
        ->and($attendance->fresh()->check_out)->toBe('17:00:00');
});

it('clears the times and events when a filled day is later marked sick', function () {
    $this->post(route('attendance.store'), manualPayload($this->employee, [
        'check_in' => '08:00',
        'check_out' => '17:00',
    ]))->assertRedirect();

    $this->post(route('attendance.store'), [
        'employee_id' => $this->employee->id,
        'date' => today()->toDateString(),
        'status' => 'sick',
        'notes' => 'Ternyata sakit',
    ])->assertRedirect();

    $attendance = Attendance::firstWhere('employee_id', $this->employee->id);

    expect($attendance->status)->toBe('sick')
        ->and($attendance->check_in)->toBeNull()
        ->and($attendance->check_out)->toBeNull()
        ->and($attendance->events)->toHaveCount(0);
});

it('ignores any times sent alongside a non-working status', function () {
    $this->post(route('attendance.store'), [
        'employee_id' => $this->employee->id,
        'date' => today()->toDateString(),
        'status' => 'sick',
        'notes' => 'Demam',
        'check_in' => '08:00',
        'check_out' => '17:00',
    ])->assertRedirect();

    $attendance = Attendance::firstWhere('employee_id', $this->employee->id);

    expect($attendance->status)->toBe('sick')
        ->and($attendance->check_in)->toBeNull()
        ->and($attendance->check_out)->toBeNull()
        ->and($attendance->events)->toHaveCount(0);
});

it('clears the audit columns belonging to a rewritten time', function () {
    Attendance::factory()->for($this->employee)->create([
        'date' => today(),
        'check_in' => '09:30:00',
        'check_in_lat' => -6.2,
        'check_in_lng' => 106.8,
        'check_in_photo_path' => 'attendance-photos/selfie.jpg',
        'face_verified' => true,
        'status' => 'late',
    ]);

    $this->post(route('attendance.store'), manualPayload($this->employee, [
        'check_in' => '08:00',
    ]))->assertRedirect();

    $attendance = Attendance::firstWhere('employee_id', $this->employee->id);

    expect($attendance->check_in_lat)->toBeNull()
        ->and($attendance->check_in_photo_path)->toBeNull()
        ->and($attendance->face_verified)->toBeFalse();
});

it('updates the same day instead of creating a second record', function () {
    $this->post(route('attendance.store'), manualPayload($this->employee))->assertRedirect();
    $this->post(route('attendance.store'), manualPayload($this->employee, [
        'check_in' => '08:30',
    ]))->assertRedirect();

    expect(Attendance::where('employee_id', $this->employee->id)->count())->toBe(1)
        ->and(Attendance::firstWhere('employee_id', $this->employee->id)->check_in)->toBe('08:30:00');
});

it('snapshots the shift that applied on the recorded date', function () {
    Setting::set('attendance_shift_enabled', true);
    $shift = Shift::factory()->create(['check_in' => '22:00:00', 'late_threshold' => '22:10:00']);
    $this->employee->update(['shift_id' => $shift->id]);

    $this->post(route('attendance.store'), manualPayload($this->employee, [
        'date' => today()->subDay()->toDateString(),
        'check_in' => '22:00',
    ]))->assertRedirect();

    $attendance = Attendance::firstWhere('employee_id', $this->employee->id);

    expect($attendance->shift_id)->toBe($shift->id)
        ->and($attendance->status)->toBe('present');
});

it('places a night shift check-out on the following day', function () {
    $this->post(route('attendance.store'), manualPayload($this->employee, [
        'check_in' => '22:00',
        'check_out' => '06:00',
    ]))->assertRedirect();

    $events = Attendance::firstWhere('employee_id', $this->employee->id)
        ->events()
        ->get()
        ->keyBy(fn ($event) => $event->type->value);

    expect($events['check_in']->occurred_at->toDateString())->toBe(today()->toDateString())
        ->and($events['check_out']->occurred_at->toDateString())->toBe(today()->addDay()->toDateString());
});

// --- notification ---

it('tells the employee their day was recorded for them', function () {
    $user = User::factory()->create();
    $this->employee->update(['user_id' => $user->id]);

    Notification::fake();

    $this->post(route('attendance.store'), [
        'employee_id' => $this->employee->id,
        'date' => today()->toDateString(),
        'status' => 'sick',
        'notes' => 'Demam',
    ])->assertRedirect();

    Notification::assertSentTo($user, EmployeeNotification::class);
});

// --- validation ---

it('validates the submitted record', function (array $payload, array $errors) {
    $this->post(route('attendance.store'), $payload)->assertSessionHasErrors($errors);
})->with([
    'missing employee' => [['date' => '2026-01-01', 'status' => 'sick', 'notes' => 'x'], ['employee_id']],
    'unknown status' => [['employee_id' => 1, 'date' => '2026-01-01', 'status' => 'leave'], ['status']],
    'bad time format' => [['employee_id' => 1, 'date' => '2026-01-01', 'status' => 'present', 'check_in' => '8 pagi'], ['check_in']],
    'missing check in' => [['employee_id' => 1, 'date' => '2026-01-01', 'status' => 'present'], ['check_in']],
    'half a break' => [['employee_id' => 1, 'date' => '2026-01-01', 'status' => 'present', 'check_in' => '08:00', 'break_start' => '12:00'], ['break_end']],
]);

it('refuses to record a day that has not happened yet', function () {
    $this->post(route('attendance.store'), manualPayload($this->employee, [
        'date' => today()->addDay()->toDateString(),
    ]))->assertSessionHasErrors(['date']);
});

it('refuses to record for an inactive employee', function () {
    $inactive = Employee::factory()->inactive()->create();

    $this->post(route('attendance.store'), manualPayload($inactive))
        ->assertSessionHasErrors(['employee_id']);
});

// --- authorization ---

it('only allows admins to record attendance', function () {
    auth()->logout();
    $this->post(route('attendance.store'), manualPayload($this->employee))
        ->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->post(route('attendance.store'), manualPayload($this->employee))
        ->assertForbidden();

    expect(Attendance::count())->toBe(0);
});

// --- deleting ---

it('deletes a record that only exists because an admin made it', function () {
    $this->post(route('attendance.store'), [
        'employee_id' => $this->employee->id,
        'date' => today()->toDateString(),
        'status' => 'permit',
        'notes' => 'Acara keluarga',
    ])->assertRedirect();

    $attendance = Attendance::firstWhere('employee_id', $this->employee->id);

    $this->delete(route('attendance.destroy', $attendance))->assertRedirect();

    expect(Attendance::count())->toBe(0);
});

it('refuses to delete a day the employee actually clocked', function () {
    $attendance = Attendance::factory()->for($this->employee)->create([
        'date' => today(),
        'check_in' => '08:00:00',
        'recorded_by' => $this->admin->id,
    ]);

    AttendanceEvent::factory()->create([
        'attendance_id' => $attendance->id,
        'type' => AttendanceEventType::CheckIn,
        'occurred_at' => today()->setTime(8, 0),
        'recorded_by' => null,
    ]);

    $this->delete(route('attendance.destroy', $attendance))->assertForbidden();

    expect(Attendance::count())->toBe(1);
});

it('refuses to delete a record no admin ever touched', function () {
    $attendance = Attendance::factory()->for($this->employee)->create(['date' => today()]);

    $this->delete(route('attendance.destroy', $attendance))->assertForbidden();
});

// --- the board the dialog is opened from ---

it('exposes what each row needs to be edited', function () {
    $this->post(route('attendance.store'), [
        'employee_id' => $this->employee->id,
        'date' => today()->toDateString(),
        'status' => 'sick',
        'notes' => 'Demam',
    ])->assertRedirect();

    $this->get(route('attendance.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('records.0.status', 'sick')
            ->where('records.0.notes', 'Demam')
            ->where('records.0.recorded_manually', true)
            ->where('records.0.can_delete', true)
            ->has('records.0.attendance_id')
        );
});

it('reports a clocked day as neither manual nor deletable', function () {
    Attendance::factory()->for($this->employee)->create(['date' => today(), 'status' => 'present']);

    $this->get(route('attendance.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('records.0.recorded_manually', false)
            ->where('records.0.can_delete', false)
        );
});
