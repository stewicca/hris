<?php

use App\Models\Employee;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Support\FeatureSettings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    // The shift routes are gated behind the shift feature toggle.
    FeatureSettings::shiftEnabled() || Setting::set('attendance_shift_enabled', true);
    $this->actingAs(User::factory()->admin()->create());
});

/** Minimal valid shift payload. */
function shiftPayload(array $override = []): array
{
    return array_merge([
        'name' => 'Shift Pagi',
        'check_in' => '08:00',
        'check_out' => '17:00',
        'late_threshold' => '08:05',
        'grace_minutes' => 0,
        'break_enabled' => false,
        'is_active' => true,
    ], $override);
}

test('shift index lists configured shifts', function () {
    Shift::factory()->create(['name' => 'Shift Pagi']);

    $this->get(route('shifts.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('shifts/index')
            ->has('shifts', 1)
        );
});

test('admin can create a shift', function () {
    $this->post(route('shifts.store'), shiftPayload())
        ->assertRedirect();

    expect(Shift::where('name', 'Shift Pagi')->exists())->toBeTrue();
});

test('shift store requires a break window when break is enabled', function () {
    $this->post(route('shifts.store'), shiftPayload([
        'break_enabled' => true,
        // break_start / break_end omitted
    ]))
        ->assertSessionHasErrors(['break_start', 'break_end']);
});

test('admin can update a shift', function () {
    $shift = Shift::factory()->create(['name' => 'Lama']);

    $this->put(route('shifts.update', $shift), shiftPayload(['name' => 'Baru']))
        ->assertRedirect();

    expect($shift->fresh()->name)->toBe('Baru');
});

test('admin can delete a shift not in use', function () {
    $shift = Shift::factory()->create();

    $this->delete(route('shifts.destroy', $shift))
        ->assertRedirect();

    expect(Shift::find($shift->id))->toBeNull();
});

test('cannot delete a shift still assigned to an employee', function () {
    $shift = Shift::factory()->create();
    Employee::factory()->create(['shift_id' => $shift->id]);

    $this->delete(route('shifts.destroy', $shift))
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('shift routes return 404 when the feature is disabled', function () {
    Setting::set('attendance_shift_enabled', false);

    $this->get(route('shifts.index'))->assertNotFound();
});
