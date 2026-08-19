<?php

use App\AttendanceEventType;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Face recognition is exercised by its own test section below; the GPS
    // tests in the main body run with it disabled so they stay GPS-focused.
    config(['attendance.face.enabled' => false]);

    // Break & shift are opt-in; keep them off for the core flow tests.
    Setting::set('attendance_break_enabled', false);
    Setting::set('attendance_shift_enabled', false);

    $this->user = User::factory()->create();
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
});

/** Returns a valid GPS payload with a fresh timestamp. */
function gpsPayload(array $override = []): array
{
    return array_merge([
        'type' => 'check_in',
        'latitude' => -8.6705,
        'longitude' => 115.2126,
        'accuracy' => 15.0,
        'gps_timestamp' => now()->getTimestampMs(),
    ], $override);
}

// --- today ---

it('returns null attendance when employee has not checked in today', function () {
    $this->actingAs($this->user)
        ->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJson(['attendance' => null]);
});

it('returns today attendance record when it exists', function () {
    $attendance = Attendance::factory()->create([
        'employee_id' => $this->employee->id,
        'date' => today(),
        'check_in' => '08:00:00',
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/attendance/today')
        ->assertOk()
        ->assertJsonPath('attendance.id', $attendance->id);
});

it('returns 404 when user has no employee profile', function () {
    $userWithoutEmployee = User::factory()->create();

    $this->actingAs($userWithoutEmployee)
        ->getJson('/api/attendance/today')
        ->assertNotFound();
});

// --- check-in ---

it('can check in with valid gps coordinates', function () {
    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload())
        ->assertCreated()
        ->assertJsonPath('attendance.check_in', fn ($v) => $v !== null)
        ->assertJsonPath('attendance.check_in_lat', -8.6705)
        ->assertJsonPath('attendance.check_in_lng', 115.2126)
        ->assertJsonPath('attendance.check_in_accuracy', fn ($v) => $v == 15.0);

    expect(Attendance::whereDate('date', today())->where('employee_id', $this->employee->id)->exists())->toBeTrue();
});

it('records a check_in event alongside the attendance mirror', function () {
    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload())
        ->assertCreated();

    $attendance = Attendance::where('employee_id', $this->employee->id)->firstOrFail();

    expect($attendance->events)->toHaveCount(1)
        ->and($attendance->events->first()->type)->toBe(AttendanceEventType::CheckIn);
});

it('marks attendance as present when checking in before 08:05', function () {
    $this->travelTo(today()->setHour(7)->setMinute(50));

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload())
        ->assertCreated()
        ->assertJsonPath('attendance.status', 'present');
});

it('marks attendance as late when checking in after 08:05', function () {
    $this->travelTo(today()->setHour(9)->setMinute(0));

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload())
        ->assertCreated()
        ->assertJsonPath('attendance.status', 'late');
});

it('cannot check in twice on the same day', function () {
    Attendance::factory()->create([
        'employee_id' => $this->employee->id,
        'date' => today(),
        'check_in' => '08:00:00',
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('validates the type field is required', function () {
    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload(['type' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('validates latitude and longitude are required for check-in', function () {
    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload([
            'type' => 'check_in',
            'latitude' => null,
            'longitude' => null,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['latitude', 'longitude']);
});

it('validates latitude must be within valid range', function () {
    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload(['latitude' => 999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('latitude');
});

// --- GPS anti-fake validation ---

it('rejects check-in when gps accuracy is too low', function () {
    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload(['accuracy' => 200.0]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('accuracy');
});

it('rejects check-in when gps timestamp is too old', function () {
    $staleTimestamp = now()->subMinutes(5)->getTimestampMs();

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload(['gps_timestamp' => $staleTimestamp]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('gps_timestamp');
});

it('rejects check-in when accuracy and gps_timestamp are missing', function () {
    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', [
            'type' => 'check_in',
            'latitude' => -8.6705,
            'longitude' => 115.2126,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accuracy', 'gps_timestamp']);
});

// --- check-out ---

it('can check out after checking in', function () {
    Attendance::factory()->noCheckOut()->create([
        'employee_id' => $this->employee->id,
        'date' => today(),
        'check_in' => '08:00:00',
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload(['type' => 'check_out']))
        ->assertCreated()
        ->assertJsonPath('attendance.check_out', fn ($v) => $v !== null)
        ->assertJsonPath('attendance.check_out_lat', -8.6705)
        ->assertJsonPath('attendance.check_out_accuracy', fn ($v) => $v == 15.0);
});

it('cannot check out without checking in first', function () {
    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload(['type' => 'check_out']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('cannot check out twice on the same day', function () {
    Attendance::factory()->create([
        'employee_id' => $this->employee->id,
        'date' => today(),
        'check_in' => '08:00:00',
        'check_out' => '17:00:00',
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload(['type' => 'check_out']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('rejects check-out when gps accuracy is too low', function () {
    Attendance::factory()->noCheckOut()->create([
        'employee_id' => $this->employee->id,
        'date' => today(),
        'check_in' => '08:00:00',
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload([
            'type' => 'check_out',
            'accuracy' => 500.0,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('accuracy');
});

// --- history ---

it('returns attendance history for the last 30 days', function () {
    Attendance::factory()
        ->count(5)
        ->sequence(fn ($seq) => ['date' => today()->subDays($seq->index + 1)])
        ->create(['employee_id' => $this->employee->id]);

    // create one outside 30 days — should not appear
    Attendance::factory()->create([
        'employee_id' => $this->employee->id,
        'date' => now()->subDays(45),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/attendance/history')
        ->assertOk();

    expect($response->json('history'))->toHaveCount(5);
});

// --- geofence ---

it('skips geofence check when office coordinates are not configured', function () {
    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload())
        ->assertCreated();
});

it('allows check-in when employee is within the office radius', function () {
    Setting::set('office_location', [
        'latitude' => -8.6705,
        'longitude' => 115.2126,
        'radius_meters' => 100,
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload([
            'latitude' => -8.6705,
            'longitude' => 115.2126,
        ]))
        ->assertCreated();
});

it('rejects check-in when employee is outside the office radius', function () {
    Setting::set('office_location', [
        'latitude' => -8.6705,
        'longitude' => 115.2126,
        'radius_meters' => 100,
    ]);

    // Tokyo — far outside Bali office
    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload([
            'latitude' => 35.6762,
            'longitude' => 139.6503,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('location');
});

it('rejects check-out when employee is outside the office radius', function () {
    Setting::set('office_location', [
        'latitude' => -8.6705,
        'longitude' => 115.2126,
        'radius_meters' => 100,
    ]);

    Attendance::factory()->noCheckOut()->create([
        'employee_id' => $this->employee->id,
        'date' => today(),
        'check_in' => '08:00:00',
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload([
            'type' => 'check_out',
            'latitude' => 35.6762,
            'longitude' => 139.6503,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('location');
});

it('requires authentication for all attendance endpoints', function (string $method, string $uri) {
    $this->{$method}($uri)->assertUnauthorized();
})->with([
    ['getJson', '/api/attendance/today'],
    ['postJson', '/api/attendance/event'],
    ['getJson', '/api/attendance/history'],
    ['getJson', '/api/attendance/settings'],
]);

// --- settings endpoint ---

it('exposes configured office hours via the settings endpoint', function () {
    $this->actingAs($this->user)
        ->getJson('/api/attendance/settings')
        ->assertOk()
        ->assertJsonPath('office_hours.check_in', '08:00')
        ->assertJsonPath('office_hours.check_out', '17:00')
        ->assertJsonPath('office_hours.late_threshold', '08:05')
        ->assertJsonPath('geofence_enabled', false)
        ->assertJsonPath('break_enabled', false)
        ->assertJsonPath('shift_enabled', false);
});

it('reflects custom office hours set by admin in the settings endpoint', function () {
    Setting::set('office_hours', [
        'check_in' => '09:00',
        'check_out' => '18:00',
        'late_threshold' => '09:15',
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/attendance/settings')
        ->assertOk()
        ->assertJsonPath('office_hours.late_threshold', '09:15');
});

it('does not leak office coordinates in the settings endpoint', function () {
    Setting::set('office_location', ['latitude' => -6.2, 'longitude' => 106.8, 'radius_meters' => 100]);

    $this->actingAs($this->user)
        ->getJson('/api/attendance/settings')
        ->assertOk()
        ->assertJsonPath('geofence_enabled', true)
        ->assertJsonMissingPath('latitude')
        ->assertJsonMissingPath('longitude');
});

// --- configurable late threshold ---

it('marks attendance as late based on configured threshold', function () {
    Setting::set('office_hours', [
        'check_in' => '09:00',
        'check_out' => '18:00',
        'late_threshold' => '09:15',
    ]);

    // 09:20 is after the configured 09:15 threshold → late
    $this->travelTo(today()->setHour(9)->setMinute(20));

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload())
        ->assertCreated()
        ->assertJsonPath('attendance.status', 'late');

    // A check-in at 09:10 (before 09:15) would be present
    $this->travelBack();
    $this->travelTo(today()->setHour(9)->setMinute(10));

    $otherEmployee = Employee::factory()->create(['user_id' => User::factory()->create()->id]);
    $this->actingAs($otherEmployee->user)
        ->postJson('/api/attendance/event', gpsPayload())
        ->assertCreated()
        ->assertJsonPath('attendance.status', 'present');
});

// --- geofence via settings (not only config) ---

it('rejects check-in outside the radius configured via settings', function () {
    Setting::set('office_location', ['latitude' => -8.6705, 'longitude' => 115.2126, 'radius_meters' => 100]);

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload([
            'latitude' => 35.6762,
            'longitude' => 139.6503,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('location');
});

// ---------------------------------------------------------------------------
// Break flow (istirahat)
// ---------------------------------------------------------------------------

it('records break events when the break feature is enabled', function () {
    Setting::set('attendance_break_enabled', true);

    Attendance::factory()->noCheckOut()->create([
        'employee_id' => $this->employee->id,
        'date' => today(),
        'check_in' => '08:00:00',
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload(['type' => 'break_start']))
        ->assertCreated()
        ->assertJsonPath('attendance.break_start', fn ($v) => $v !== null)
        ->assertJsonPath('attendance.break_end', null);

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload(['type' => 'break_end']))
        ->assertCreated()
        ->assertJsonPath('attendance.break_end', fn ($v) => $v !== null);
});

it('allows skipping break and checking out directly after check-in', function () {
    Setting::set('attendance_break_enabled', true);

    Attendance::factory()->noCheckOut()->create([
        'employee_id' => $this->employee->id,
        'date' => today(),
        'check_in' => '08:00:00',
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload(['type' => 'check_out']))
        ->assertCreated()
        ->assertJsonPath('attendance.check_out', fn ($v) => $v !== null)
        ->assertJsonPath('attendance.break_start', null);
});

it('rejects break_start before break_end when break is enabled', function () {
    Setting::set('attendance_break_enabled', true);

    Attendance::factory()->noCheckOut()->create([
        'employee_id' => $this->employee->id,
        'date' => today(),
        'check_in' => '08:00:00',
        'break_start' => '12:00:00',
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload(['type' => 'break_start']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('ignores break events when the break feature is disabled', function () {
    // break disabled (default) — break_start should be rejected as unexpected
    Attendance::factory()->noCheckOut()->create([
        'employee_id' => $this->employee->id,
        'date' => today(),
        'check_in' => '08:00:00',
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload(['type' => 'break_start']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

// ---------------------------------------------------------------------------
// Shift-aware late detection
// ---------------------------------------------------------------------------

it('uses the employee shift threshold when shift mode is enabled', function () {
    Setting::set('attendance_shift_enabled', true);

    $shift = Shift::factory()->create([
        'check_in' => '14:00:00',
        'check_out' => '22:00:00',
        'late_threshold' => '14:10:00',
    ]);
    $this->employee->update(['shift_id' => $shift->id]);

    // 14:20 is after the shift's 14:10 threshold → late
    $this->travelTo(today()->setHour(14)->setMinute(20));

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload())
        ->assertCreated()
        ->assertJsonPath('attendance.status', 'late')
        ->assertJsonPath('attendance.shift_id', $shift->id);
});

it('falls back to global office hours when shift mode is disabled', function () {
    // assign a shift but keep shift mode off — threshold should stay global
    $shift = Shift::factory()->create([
        'late_threshold' => '22:10:00',
    ]);
    $this->employee->update(['shift_id' => $shift->id]);

    $this->travelTo(today()->setHour(9)->setMinute(0));

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload())
        ->assertCreated()
        ->assertJsonPath('attendance.status', 'late'); // 09:00 > global 08:05
});

it('honors the shift grace period when determining late status', function () {
    Setting::set('attendance_shift_enabled', true);

    $shift = Shift::factory()->create([
        'check_in' => '08:00:00',
        'check_out' => '17:00:00',
        'late_threshold' => '08:05:00',
        'grace_minutes' => 10, // effectively late only after 08:15
    ]);
    $this->employee->update(['shift_id' => $shift->id]);

    // 08:10 is after the 08:05 threshold but within the 10-minute grace → present
    $this->travelTo(today()->setHour(8)->setMinute(10));

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload())
        ->assertCreated()
        ->assertJsonPath('attendance.status', 'present');
});

// ---------------------------------------------------------------------------
// Face verification
// ---------------------------------------------------------------------------
//
// These tests enable face recognition and mock the Python microservice via
// Http::fake(). A real image is generated with UploadedFile::fake() — the
// bytes never reach the (absent) service in tests. Each test re-enables face
// because the file-level beforeEach disables it for the GPS-focused tests.

function fakeFaceImage(): UploadedFile
{
    // GD is unavailable in the test container, so we can't synthesize a JPEG
    // via UploadedFile::fake()->image(). Instead we embed the bytes of a real
    // 1x1 JPEG — enough for Laravel's `image|mimes:jpg` validation (which
    // inspects content via finfo) to pass. The Python service is mocked in
    // these tests, so the bytes are never decoded as a face.
    $jpeg = base64_decode(
        '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUHQ8RDREdFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAABAAEDASIAAhEBAxEB/8QAFAABAAAAAAAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AssAB/9k='
    );

    return UploadedFile::fake()->createWithContent('face.jpg', $jpeg);
}

function enableFace(): void
{
    config(['attendance.face.enabled' => true]);
}

/** Enroll `$employee` with a deterministic 512-d reference embedding. */
function enrollFace(Employee $employee): void
{
    $employee->update([
        'face_embedding' => array_fill(0, 512, 0.1),
        'face_enrolled_at' => now(),
    ]);
}

function fakeVerifyResponse(array $overrides = []): void
{
    Http::fake([
        'face-recognition:5000/verify' => Http::response(array_merge([
            'verified' => true,
            'distance' => 0.3,
            'liveness' => 'unknown',
            'detected' => true,
        ], $overrides)),
    ]);
}

it('checks in successfully when the face matches the reference', function () {
    enableFace();
    enrollFace($this->employee);
    fakeVerifyResponse(['verified' => true]);

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', array_merge(gpsPayload(), [
            'image' => fakeFaceImage(),
        ]))
        ->assertCreated()
        ->assertJsonPath('attendance.face_verified', true);

    $attendance = Attendance::where('employee_id', $this->employee->id)->first();
    expect($attendance->check_in_photo_path)->not->toBeNull()
        ->and($attendance->face_verified)->toBeTrue();
});

it('rejects check-in when the face does not match', function () {
    enableFace();
    enrollFace($this->employee);
    fakeVerifyResponse(['verified' => false, 'distance' => 0.9]);

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', array_merge(gpsPayload(), [
            'image' => fakeFaceImage(),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');
});

it('rejects check-in when a spoof (photo of a photo) is detected', function () {
    enableFace();
    enrollFace($this->employee);
    fakeVerifyResponse(['verified' => true, 'distance' => 0.2, 'liveness' => 'spoof']);

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', array_merge(gpsPayload(), [
            'image' => fakeFaceImage(),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');
});

it('rejects check-in when no face is detected in the probe', function () {
    enableFace();
    enrollFace($this->employee);
    fakeVerifyResponse(['verified' => false, 'detected' => false]);

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', array_merge(gpsPayload(), [
            'image' => fakeFaceImage(),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');
});

it('blocks check-in when the employee is not enrolled and enrollment is required', function () {
    enableFace();
    Http::fake();

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', array_merge(gpsPayload(), [
            'image' => fakeFaceImage(),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('face');
});

it('requires the image field when face recognition is enabled', function () {
    enableFace();

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', gpsPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');
});

it('fail-closes (rejects) when the face service is unreachable', function () {
    enableFace();
    enrollFace($this->employee);
    Http::fake(fn () => throw new ConnectionException('unreachable'));

    $this->actingAs($this->user)
        ->postJson('/api/attendance/event', array_merge(gpsPayload(), [
            'image' => fakeFaceImage(),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');
});
