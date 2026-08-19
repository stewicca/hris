<?php

use App\Models\ApiLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('attendance:mark-absentees')->dailyAt('23:30');

// Trims api_logs to config('hris.api_log_retention_days').
Schedule::command('model:prune', ['--model' => [ApiLog::class]])->dailyAt('03:15');
