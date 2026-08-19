<?php

use App\Models\ApiLog;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;

it('never stores a submitted password', function () {
    User::factory()->create(['username' => 'budi', 'password' => bcrypt('s3cr3t-p4ssw0rd')]);

    $this->postJson('/api/login', [
        'email' => 'budi',
        'password' => 's3cr3t-p4ssw0rd',
    ])->assertOk();

    $log = ApiLog::latest('id')->first();

    expect($log->request_body)->not->toContain('s3cr3t-p4ssw0rd')
        ->and($log->request_body)->toContain('[redacted]')
        ->and($log->request_body)->toContain('budi');
});

it('never stores a password on a failed login either', function () {
    $this->postJson('/api/login', [
        'email' => 'budi',
        'password' => 'guessed-password',
    ])->assertUnprocessable();

    expect(ApiLog::latest('id')->first()->request_body)->not->toContain('guessed-password');
});

it('redacts credential-bearing request headers', function () {
    $this->withHeaders([
        'Cookie' => 'laravel_session=abc123deadbeef',
        'Authorization' => 'Bearer tok_abc123deadbeef',
    ])->getJson('/api/status')->assertOk();

    $headers = ApiLog::latest('id')->first()->request_headers;
    $flat = json_encode($headers);

    expect($flat)->not->toContain('abc123deadbeef')
        ->and($headers['cookie'])->toBe('[redacted]')
        ->and($headers['authorization'])->toBe('[redacted]');
});

it('reduces an uploaded file to a descriptor instead of storing its bytes', function () {
    // The upload does not have to be a valid image: the log is written by
    // middleware wrapping the request, so it is recorded whether validation
    // passes or fails. Using create() keeps the test off the GD extension.
    config(['attendance.face.enabled' => false]);
    Setting::set('attendance_break_enabled', false);
    Setting::set('attendance_shift_enabled', false);

    $user = User::factory()->create();
    Employee::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->postJson('/api/attendance/event', [
        'type' => 'check_in',
        'latitude' => -8.6705,
        'longitude' => 115.2126,
        'accuracy' => 15.0,
        'gps_timestamp' => now()->getTimestampMs(),
        'image' => UploadedFile::fake()->create('selfie.jpg', 64, 'image/jpeg'),
    ]);

    $body = ApiLog::latest('id')->first()->request_body;

    expect($body)->toContain('[file: selfie.jpg')
        ->and(strlen($body))->toBeLessThan(2000);
});

it('keeps the response body for failures', function () {
    $this->postJson('/api/login', ['email' => 'nobody', 'password' => 'nope'])
        ->assertUnprocessable();

    expect(ApiLog::latest('id')->first()->response_body)->not->toBeNull();
});

it('discards the response body on success', function () {
    $this->getJson('/api/status')->assertOk();

    expect(ApiLog::latest('id')->first())
        ->response_body->toBeNull()
        ->status_code->toBe(200);
});

it('prunes logs past the retention window', function () {
    config(['hris.api_log_retention_days' => 14]);

    $stale = ApiLog::create(['method' => 'GET', 'path' => 'api/status', 'status_code' => 200]);
    $stale->forceFill(['created_at' => now()->subDays(15)])->save();

    $fresh = ApiLog::create(['method' => 'GET', 'path' => 'api/status', 'status_code' => 200]);

    expect((new ApiLog)->prunable()->pluck('id')->all())->toBe([$stale->id]);

    (new ApiLog)->prunable()->delete();

    expect(ApiLog::pluck('id')->all())->toContain($fresh->id)
        ->and(ApiLog::find($stale->id))->toBeNull();
});
