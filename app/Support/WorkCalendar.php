<?php

namespace App\Support;

use App\Models\Holiday;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class WorkCalendar
{
    /** @var list<int> ISO weekday numbers: 1=Mon … 7=Sun */
    public const DEFAULT_WORKING_DAYS = [1, 2, 3, 4, 5];

    /**
     * The configured working weekdays (ISO numbers).
     *
     * @return list<int>
     */
    public static function workingDays(): array
    {
        return Setting::get('working_days', self::DEFAULT_WORKING_DAYS);
    }

    /**
     * A date is a working day when its weekday is configured and it is not a holiday.
     */
    public static function isWorkingDay(CarbonInterface|string $date): bool
    {
        $date = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return in_array($date->isoWeekday(), self::workingDays(), true)
            && ! self::holidayDates()->contains($date->toDateString());
    }

    /**
     * All holiday dates as Y-m-d strings. Cached until a holiday changes.
     *
     * @return Collection<int, string>
     */
    public static function holidayDates(): Collection
    {
        return Cache::rememberForever(
            'holiday_dates',
            fn () => Holiday::query()->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString()),
        );
    }

    public static function forgetHolidayCache(): void
    {
        Cache::forget('holiday_dates');
    }
}
