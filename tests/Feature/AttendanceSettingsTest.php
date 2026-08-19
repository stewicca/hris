<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\AttendanceSettings;

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
});

// --- office hours ---

it('shows the attendance settings page with current config', function () {
    $this->get(route('attendance-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('attendance-settings/index')
            ->has('officeHours.check_in')
            ->has('officeHours.check_out')
            ->has('officeHours.late_threshold')
            ->has('breakWindow.break_start')
            ->has('breakWindow.break_end')
        );
});

it('updates office hours', function () {
    $this->put(route('attendance-settings.hours.update'), [
        'check_in' => '09:00',
        'check_out' => '18:00',
        'late_threshold' => '09:15',
    ])->assertRedirect();

    expect(Setting::get('office_hours'))->toBe([
        'check_in' => '09:00',
        'check_out' => '18:00',
        'late_threshold' => '09:15',
    ]);
});

it('validates office hours', function (array $payload, array $errors) {
    $this->put(route('attendance-settings.hours.update'), $payload)
        ->assertSessionHasErrors($errors);
})->with([
    'missing check_in' => [['check_out' => '17:00', 'late_threshold' => '08:05'], ['check_in']],
    'invalid format' => [['check_in' => '8 AM', 'check_out' => '17:00', 'late_threshold' => '08:05'], ['check_in']],
    'check_out before check_in' => [['check_in' => '17:00', 'check_out' => '08:00', 'late_threshold' => '08:05'], ['check_out']],
]);

it('only allows admins to view attendance settings', function () {
    auth()->logout();
    $this->get(route('attendance-settings.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->get(route('attendance-settings.index'))
        ->assertForbidden();
});

// --- office location ---

it('updates office location with coordinates', function () {
    $this->put(route('attendance-settings.location.update'), [
        'enable_geofence' => true,
        'latitude' => -6.2088,
        'longitude' => 106.8456,
        'radius_meters' => 150,
    ])->assertRedirect();

    $location = Setting::get('office_location');
    expect($location['latitude'])->toBe(-6.2088)
        ->and($location['longitude'])->toBe(106.8456)
        ->and($location['radius_meters'])->toBe(150);
});

it('disables geofence when enable flag is false', function () {
    // Pre-set an enabled location.
    Setting::set('office_location', ['latitude' => -6.2, 'longitude' => 106.8, 'radius_meters' => 100]);

    $this->put(route('attendance-settings.location.update'), [
        'enable_geofence' => false,
        'latitude' => -6.2,
        'longitude' => 106.8,
        'radius_meters' => 100,
    ])->assertRedirect();

    $location = Setting::get('office_location');
    expect($location['latitude'])->toBeNull()
        ->and($location['longitude'])->toBeNull()
        ->and($location['radius_meters'])->toBe(100);
});

it('validates radius bounds', function () {
    $this->put(route('attendance-settings.location.update'), [
        'enable_geofence' => false,
        'radius_meters' => 5,
    ])->assertSessionHasErrors(['radius_meters']);
});

// --- break window ---

it('updates the global break window', function () {
    $this->put(route('attendance-settings.break.update'), [
        'break_start' => '12:30',
        'break_end' => '13:30',
    ])->assertRedirect();

    expect(Setting::get('break_window'))->toBe([
        'break_start' => '12:30',
        'break_end' => '13:30',
    ]);
});

it('validates the break window', function (array $payload, array $errors) {
    $this->put(route('attendance-settings.break.update'), $payload)
        ->assertSessionHasErrors($errors);
})->with([
    'missing break_start' => [['break_end' => '13:00'], ['break_start']],
    'invalid format' => [['break_start' => 'noon', 'break_end' => '13:00'], ['break_start']],
    'break_end before break_start' => [['break_start' => '14:00', 'break_end' => '12:00'], ['break_end']],
]);

it('exposes the break window via the AttendanceSettings helper', function () {
    Setting::set('break_window', ['break_start' => '11:45', 'break_end' => '12:45']);

    expect(AttendanceSettings::breakWindow())->toBe([
        'break_start' => '11:45',
        'break_end' => '12:45',
    ]);
});

// --- AttendanceSettings helper ---

it('reports no geofence when nothing has been configured', function () {
    $location = AttendanceSettings::officeLocation();

    expect($location['latitude'])->toBeNull()
        ->and($location['longitude'])->toBeNull()
        ->and($location['radius_meters'])->toBe((float) AttendanceSettings::DEFAULT_RADIUS_METERS);
});

it('reads the office location from the settings store', function () {
    Setting::set('office_location', ['latitude' => -6.2, 'longitude' => 106.8, 'radius_meters' => 200]);

    $location = AttendanceSettings::officeLocation();

    expect($location['latitude'])->toBe(-6.2)
        ->and($location['longitude'])->toBe(106.8)
        ->and($location['radius_meters'])->toBe(200.0);
});

it('keeps the stored radius while the geofence is disabled', function () {
    Setting::set('office_location', ['latitude' => null, 'longitude' => null, 'radius_meters' => 250]);

    $location = AttendanceSettings::officeLocation();

    expect($location['latitude'])->toBeNull()
        ->and($location['radius_meters'])->toBe(250.0);
});
