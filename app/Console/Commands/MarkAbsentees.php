<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use App\Support\WorkCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class MarkAbsentees extends Command
{
    protected $signature = 'attendance:mark-absentees {--date= : Date to evaluate (Y-m-d), defaults to today}';

    protected $description = 'Mark active employees with no attendance on a working day as absent';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : today();

        if (! WorkCalendar::isWorkingDay($date)) {
            $this->info("{$date->toDateString()} bukan hari kerja — dilewati.");

            return self::SUCCESS;
        }

        $onLeaveIds = Leave::query()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('employee_id');

        $accountedIds = Attendance::query()
            ->whereDate('date', $date)
            ->pluck('employee_id');

        $absentees = Employee::query()
            ->where('status', 'active')
            ->whereNotIn('id', $accountedIds)
            ->whereNotIn('id', $onLeaveIds)
            ->get();

        foreach ($absentees as $employee) {
            $employee->attendances()->create([
                'date' => $date->toDateString(),
                'status' => 'absent',
            ]);
        }

        $this->info("Ditandai tidak hadir: {$absentees->count()} karyawan pada {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
