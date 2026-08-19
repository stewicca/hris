<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bootstrap Administrator
    |--------------------------------------------------------------------------
    |
    | The initial administrator account created by ProductionSeeder. In
    | production both ADMIN_EMAIL and ADMIN_PASSWORD are mandatory and the
    | seeder aborts when either is missing — there is deliberately no default
    | credential to fall back on. Outside production the seeder substitutes a
    | well-known development account.
    |
    */

    'admin' => [
        'username' => env('ADMIN_USERNAME', 'admin'),
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Shift
    |--------------------------------------------------------------------------
    |
    | A fresh production database needs at least one active shift, otherwise
    | employees have no office hours, late threshold or grace period to be
    | measured against. Seeded once by ProductionSeeder; editable afterwards
    | through the Shifts screen.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | API Log Retention
    |--------------------------------------------------------------------------
    |
    | How many days of api_logs rows to keep. Pruned nightly by the scheduled
    | `model:prune` command. The table records every /api request, so an
    | unbounded window fills the disk.
    |
    */

    'api_log_retention_days' => (int) env('API_LOG_RETENTION_DAYS', 14),

    'default_shift' => [
        'name' => env('DEFAULT_SHIFT_NAME', 'Reguler 08:00-17:00'),
        'check_in' => env('DEFAULT_SHIFT_CHECK_IN', '08:00:00'),
        'check_out' => env('DEFAULT_SHIFT_CHECK_OUT', '17:00:00'),
        'late_threshold' => env('DEFAULT_SHIFT_LATE_THRESHOLD', '08:15:00'),
        'grace_minutes' => (int) env('DEFAULT_SHIFT_GRACE_MINUTES', 0),
    ],

];
