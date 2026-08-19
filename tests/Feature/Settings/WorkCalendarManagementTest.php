<?php

use App\Models\Holiday;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->admin = User::factory()->admin()->create();
});

it('shows the work calendar to an admin', function () {
    $this->actingAs($this->admin)
        ->get(route('work-calendar.index'))
        ->assertOk();
});

it('lets an admin update the working days', function () {
    $this->actingAs($this->admin)
        ->put(route('work-calendar.update'), ['working_days' => [1, 2, 3]])
        ->assertRedirect();

    expect(Setting::get('working_days'))->toBe([1, 2, 3]);
});

it('validates the working days', function () {
    $this->actingAs($this->admin)
        ->put(route('work-calendar.update'), ['working_days' => [9]])
        ->assertSessionHasErrors('working_days.0');
});

it('lets an admin add and remove a holiday', function () {
    $this->actingAs($this->admin)
        ->post(route('work-calendar.holidays.store'), ['date' => '2026-08-17', 'name' => 'HUT RI'])
        ->assertRedirect();

    $holiday = Holiday::sole();
    expect($holiday->name)->toBe('HUT RI');

    $this->actingAs($this->admin)
        ->delete(route('work-calendar.holidays.destroy', $holiday))
        ->assertRedirect();

    expect(Holiday::count())->toBe(0);
});

it('rejects a duplicate holiday date', function () {
    Holiday::factory()->create(['date' => '2026-08-17']);

    $this->actingAs($this->admin)
        ->post(route('work-calendar.holidays.store'), ['date' => '2026-08-17', 'name' => 'HUT RI'])
        ->assertSessionHasErrors('date');
});

it('forbids non-admins from the work calendar', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('work-calendar.index'))->assertForbidden();
    $this->actingAs($user)->put(route('work-calendar.update'), ['working_days' => [1]])->assertForbidden();
});
