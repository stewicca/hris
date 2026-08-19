<?php

// Force testing environment variables at the absolute earliest point to stop
// container OS-level variables (DB_CONNECTION=mysql, CACHE_STORE=redis, etc.,
// injected by compose) from bleeding into the test runner. putenv() alone is
// not enough: Laravel's env repository reads $_SERVER/$_ENV before getenv(),
// so the OS values win unless we overwrite those superglobals here too.
// Without this, the suite runs against the live MySQL dev DB and RefreshDatabase
// can migrate:fresh it away.
$forcedTestEnv = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'SESSION_DRIVER' => 'array',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
];

foreach ($forcedTestEnv as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__.'/../vendor/autoload.php';
