<?php

use App\Models\Holiday;
use App\Models\Setting;
use App\Support\WorkCalendar;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

it('treats configured weekdays as working days by default', function () {
    expect(WorkCalendar::isWorkingDay('2026-06-29'))->toBeTrue()  // Monday
        ->and(WorkCalendar::isWorkingDay('2026-06-27'))->toBeFalse()  // Saturday
        ->and(WorkCalendar::isWorkingDay('2026-06-28'))->toBeFalse(); // Sunday
});

it('treats a holiday as a non-working day even on a weekday', function () {
    Holiday::factory()->create(['date' => '2026-06-29']); // a Monday

    expect(WorkCalendar::isWorkingDay('2026-06-29'))->toBeFalse();
});

it('respects a custom working-day configuration', function () {
    Setting::set('working_days', [6, 7]); // weekend only

    expect(WorkCalendar::isWorkingDay('2026-06-27'))->toBeTrue()   // Saturday
        ->and(WorkCalendar::isWorkingDay('2026-06-29'))->toBeFalse(); // Monday
});
