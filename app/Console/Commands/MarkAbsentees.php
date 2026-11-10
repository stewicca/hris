<?php

namespace App\Console\Commands;

use App\Actions\Attendance\MarkDailyAbsentees;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class MarkAbsentees extends Command
{
    protected $signature = 'attendance:mark-absentees {--date= : Date to evaluate (Y-m-d), defaults to today}';

    protected $description = 'Mark active employees with no attendance on a working day as absent';

    public function handle(MarkDailyAbsentees $markDailyAbsentees): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : today();

        $count = $markDailyAbsentees->handle($date);

        if ($count < 0) {
            $this->info("{$date->toDateString()} bukan hari kerja — dilewati.");

            return self::SUCCESS;
        }

        $this->info("Ditandai tidak hadir: {$count} karyawan pada {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
