<?php

use App\AttendanceEventType;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\KioskDevice;
use App\Models\Setting;
use App\Support\FaceMatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Setting::set('kiosk_enabled', true);
    Setting::set('attendance_break_enabled', false);
    Setting::set('attendance_shift_enabled', false);

    Storage::fake('local');

    $this->token = Str::random(64);
    $this->device = KioskDevice::factory()->withToken($this->token)->create();

    FaceMatcher::forgetEnrolledEmbeddings();
});

/**
 * A 512-dimension vector pointing along a single axis. Two different axes are
 * orthogonal, so their cosine distance is exactly 1.0 — the far end of the
 * scale. FaceMatcher normalises whatever it is given, so these do not have to
 * be unit vectors themselves.
 *
 * @return list<float>
 */
function axis(int $index): array
{
    $vector = array_fill(0, 512, 0.0);
    $vector[$index] = 1.0;

    return $vector;
}

/**
 * A vector tilted `$weight` of the way from one axis toward another, for
 * building faces that sit a controlled distance apart.
 *
 * @return list<float>
 */
function tilted(int $from, int $toward, float $weight): array
{
    $vector = array_fill(0, 512, 0.0);
    $vector[$from] = 1.0 - $weight;
    $vector[$toward] = $weight;

    return $vector;
}

/**
 * Stand in for the Python service's /embed response.
 *
 * @param  list<float>  $embedding
 */
function fakeEmbed(array $embedding, string $liveness = 'real'): void
{
    Http::fake([
        '*/embed' => Http::response([
            'embedding' => $embedding,
            'detected' => true,
            'liveness' => $liveness,
        ]),
        '*/health' => Http::response(['status' => 'ok']),
    ]);
}

/**
 * GD is unavailable in the test container, so a JPEG cannot be synthesized via
 * UploadedFile::fake()->image(). These are the bytes of a real 1x1 JPEG, which
 * is enough for the `image|mimes:jpg` rules (they inspect content via finfo).
 * The face service is mocked, so the bytes are never decoded as a face.
 */
/**
 * A face source whose embedding can be changed mid-test.
 *
 * Http::fake() appends stubs and the first match wins, so calling it a second
 * time does not replace an earlier stub for the same URL. The stub reads this
 * handle on every call instead, which lets one test present two different
 * faces to the terminal.
 */
function fakeEmbedSource(array $embedding): object
{
    $probe = new stdClass;
    $probe->embedding = $embedding;

    Http::fake([
        '*/embed' => fn () => Http::response([
            'embedding' => $probe->embedding,
            'detected' => true,
            'liveness' => 'real',
        ]),
        '*/health' => Http::response(['status' => 'ok']),
    ]);

    return $probe;
}

function scanPhoto(): UploadedFile
{
    $jpeg = base64_decode(
        '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUHQ8RDREdFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAABAAEDASIAAhEBAxEB/8QAFAABAAAAAAAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AssAB/9k='
    );

    return UploadedFile::fake()->createWithContent('scan.jpg', $jpeg);
}

// --- device authentication ---

it('hides the kiosk entirely when the feature is switched off', function () {
    Setting::set('kiosk_enabled', false);

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->assertNotFound();
});

it('rejects a token no terminal was issued', function () {
    $this->withHeader('X-Kiosk-Token', Str::random(64))
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->assertUnauthorized();
});

it('rejects a terminal that has been deactivated', function () {
    $token = Str::random(64);
    KioskDevice::factory()->withToken($token)->inactive()->create();

    $this->withHeader('X-Kiosk-Token', $token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->assertUnauthorized();
});

it('rejects a terminal submitting from outside its allowed network', function () {
    $token = Str::random(64);
    KioskDevice::factory()->withToken($token)->restrictedTo(['198.51.100.0/24'])->create();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeader('X-Kiosk-Token', $token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->assertForbidden();
});

it('accepts a terminal submitting from inside its allowed network', function () {
    $token = Str::random(64);
    KioskDevice::factory()->withToken($token)->restrictedTo(['198.51.100.0/24'])->create();
    Employee::factory()->create(['face_embedding' => axis(0)]);
    fakeEmbed(axis(0));

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeader('X-Kiosk-Token', $token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->assertOk();
});

it('records when a terminal was last seen', function () {
    Employee::factory()->create(['face_embedding' => axis(0)]);
    fakeEmbed(axis(0));

    expect($this->device->last_seen_at)->toBeNull();

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()]);

    expect($this->device->fresh()->last_seen_at)->not->toBeNull();
});

// --- identification (1:N) ---

it('identifies the nearest enrolled employee', function () {
    $match = Employee::factory()->create(['name' => 'Budi', 'face_embedding' => axis(0)]);
    Employee::factory()->create(['name' => 'Sari', 'face_embedding' => axis(1)]);

    fakeEmbed(axis(0));

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->assertOk()
        ->assertJsonPath('employee.name', 'Budi')
        ->assertJsonPath('employee.employee_number', $match->employee_number)
        ->assertJsonPath('next_action', AttendanceEventType::CheckIn->value);
});

it('refuses to guess between two employees the roster cannot tell apart', function () {
    Employee::factory()->create(['name' => 'Kembar A', 'face_embedding' => tilted(0, 1, 0.10)]);
    Employee::factory()->create(['name' => 'Kembar B', 'face_embedding' => tilted(0, 1, 0.15)]);

    // Both are well inside the threshold; what disqualifies the winner is that
    // the runner-up is almost exactly as close.
    fakeEmbed(axis(0));

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'ambiguous');
});

it('refuses a face that matches nobody on the roster', function () {
    Employee::factory()->create(['face_embedding' => axis(0)]);
    Employee::factory()->create(['face_embedding' => axis(1)]);

    fakeEmbed(axis(7));

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'no_match');
});

it('refuses a photo held up to the camera', function () {
    Employee::factory()->create(['face_embedding' => axis(0)]);

    fakeEmbed(axis(0), liveness: 'spoof');

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'spoof');
});

it('blames the service, not the face, when the service is unreachable', function () {
    Employee::factory()->create(['face_embedding' => axis(0)]);

    Http::fake(fn () => throw new ConnectionException('service down'));

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'service_unavailable');
});

it('says so when nobody has been enrolled yet', function () {
    fakeEmbed(axis(0));

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'no_enrolled_faces');
});

it('recognises a face enrolled after the roster was already cached', function () {
    Employee::factory()->create(['face_embedding' => axis(0)]);
    $probe = fakeEmbedSource(axis(0));

    // Warms the cached roster.
    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->assertOk();

    Employee::factory()->create(['name' => 'Baru', 'face_embedding' => axis(3)]);
    $probe->embedding = axis(3);

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->assertOk()
        ->assertJsonPath('employee.name', 'Baru');
});

it('greets an employee who has already finished for the day', function () {
    $employee = Employee::factory()->create(['name' => 'Budi', 'face_embedding' => axis(0)]);
    Attendance::factory()->create([
        'employee_id' => $employee->id,
        'date' => today(),
        'check_in' => '08:00:00',
        'check_out' => '17:00:00',
    ]);

    fakeEmbed(axis(0));

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'already_complete');
});

// --- recording ---

it('records attendance for the identified employee once confirmed', function () {
    $employee = Employee::factory()->create(['name' => 'Budi', 'face_embedding' => axis(0)]);
    fakeEmbed(axis(0));

    $scanId = $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->json('scan_id');

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/event', ['scan_id' => $scanId])
        ->assertCreated()
        ->assertJsonPath('employee.name', 'Budi');

    $attendance = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();

    expect($attendance->check_in)->not->toBeNull()
        ->and($attendance->face_verified)->toBeTrue()
        ->and($attendance->check_in_photo_path)->not->toBeNull()
        ->and($attendance->events)->toHaveCount(1)
        ->and($attendance->events->first()->type)->toBe(AttendanceEventType::CheckIn);
});

it('spends a scan only once', function () {
    Employee::factory()->create(['face_embedding' => axis(0)]);
    fakeEmbed(axis(0));

    $scanId = $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->json('scan_id');

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/event', ['scan_id' => $scanId])
        ->assertCreated();

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/event', ['scan_id' => $scanId])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'scan_expired');

    expect(Attendance::query()->count())->toBe(1);
});

it('refuses a scan redeemed at a different terminal', function () {
    Employee::factory()->create(['face_embedding' => axis(0)]);
    fakeEmbed(axis(0));

    $scanId = $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()])
        ->json('scan_id');

    $otherToken = Str::random(64);
    KioskDevice::factory()->withToken($otherToken)->create();

    $this->withHeader('X-Kiosk-Token', $otherToken)
        ->postJson('/api/kiosk/event', ['scan_id' => $scanId])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'scan_foreign_device');
});

it('never lets the terminal name the employee itself', function () {
    $employee = Employee::factory()->create(['face_embedding' => axis(0)]);

    // No scan was ever issued, so an invented id — or a smuggled employee_id —
    // buys nothing: identity lives only in the server-side scan.
    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/event', [
            'scan_id' => (string) Str::ulid(),
            'employee_id' => $employee->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'scan_expired');

    expect(Attendance::query()->count())->toBe(0);
});

it('walks the same timeline as the employee portal', function () {
    $employee = Employee::factory()->create(['face_embedding' => axis(0)]);
    fakeEmbed(axis(0));

    $checkIn = $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()]);
    $checkIn->assertJsonPath('next_action', AttendanceEventType::CheckIn->value);

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/event', ['scan_id' => $checkIn->json('scan_id')])
        ->assertCreated();

    $checkOut = $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/identify', ['image' => scanPhoto()]);
    $checkOut->assertJsonPath('next_action', AttendanceEventType::CheckOut->value);

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->postJson('/api/kiosk/event', ['scan_id' => $checkOut->json('scan_id')])
        ->assertCreated();

    $attendance = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();

    expect($attendance->check_out)->not->toBeNull()
        ->and($attendance->events)->toHaveCount(2);
});

// --- terminal configuration ---

it('tells the terminal whether the face service is answering', function () {
    Http::fake(['*/health' => Http::response(['status' => 'ok'])]);

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->getJson('/api/kiosk/settings')
        ->assertOk()
        ->assertJsonPath('face_service_operational', true)
        ->assertJsonPath('device.name', $this->device->name);
});

it('tells the terminal when the face service is down', function () {
    Http::fake(fn () => throw new ConnectionException('service down'));

    $this->withHeader('X-Kiosk-Token', $this->token)
        ->getJson('/api/kiosk/settings')
        ->assertOk()
        ->assertJsonPath('face_service_operational', false);
});
