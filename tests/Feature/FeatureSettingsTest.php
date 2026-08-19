<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\FeatureSettings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
});

/** Submit every toggle in one payload (the real form does this). */
function featurePayload(array $override = []): array
{
    return array_merge([
        'leave_enabled' => true,
        'attendance_break_enabled' => false,
        'attendance_shift_enabled' => false,
        // Required by FeatureSettingController::update. Omitting it made the
        // request fail validation and redirect back, which assertRedirect()
        // happily accepted while nothing was actually saved.
        'payroll_enabled' => true,
    ], $override);
}

it('shows the feature settings page with all toggles', function () {
    $this->get(route('feature-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('feature-settings/index')
            ->where('leaveEnabled', true)
            ->where('breakEnabled', false)
            ->where('shiftEnabled', false)
        );
});

it('defaults to leave enabled when no setting is stored', function () {
    expect(FeatureSettings::leaveEnabled())->toBeTrue();
});

it('enables the leave feature', function () {
    Setting::set('leave_enabled', false);
    expect(FeatureSettings::leaveEnabled())->toBeFalse();

    $this->put(route('feature-settings.update'), featurePayload([
        'leave_enabled' => true,
    ]))->assertRedirect();

    expect(Setting::get('leave_enabled'))->toBeTrue()
        ->and(FeatureSettings::leaveEnabled())->toBeTrue();
});

it('disables the leave feature', function () {
    $this->put(route('feature-settings.update'), featurePayload([
        'leave_enabled' => false,
    ]))->assertRedirect();

    expect(Setting::get('leave_enabled'))->toBeFalse()
        ->and(FeatureSettings::leaveEnabled())->toBeFalse();
});

it('enables the break and shift features', function () {
    $this->put(route('feature-settings.update'), featurePayload([
        'attendance_break_enabled' => true,
        'attendance_shift_enabled' => true,
    ]))->assertRedirect();

    expect(FeatureSettings::breakEnabled())->toBeTrue()
        ->and(FeatureSettings::shiftEnabled())->toBeTrue();
});

it('validates the leave_enabled field', function () {
    $this->put(route('feature-settings.update'), featurePayload([
        'leave_enabled' => 'yes',
    ]))->assertSessionHasErrors(['leave_enabled']);
});

it('only allows admins to manage feature settings', function () {
    auth()->logout();
    $this->get(route('feature-settings.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->get(route('feature-settings.index'))
        ->assertForbidden();
});

it('shares the leave feature flag with inertia', function () {
    Setting::set('leave_enabled', false);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('features.leave', false));
});
