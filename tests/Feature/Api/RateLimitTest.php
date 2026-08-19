<?php

use App\Models\Employee;
use App\Models\Setting;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['username' => 'budi']);
});

it('applies a rate limit to every api route', function () {
    $this->getJson('/api/status')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', 60);
});

it('blocks repeated login attempts against the same account', function () {
    $attempt = fn () => $this->postJson('/api/login', [
        'email' => 'budi',
        'password' => 'wrong-password',
    ]);

    foreach (range(1, 5) as $ignored) {
        $attempt()->assertUnprocessable();
    }

    $attempt()->assertStatus(429);
});

it('blocks credential stuffing that rotates the username', function () {
    // Each username is fresh, so the per-account limit of 5 never fires; only
    // the per-IP ceiling of 20 stands in the way.
    foreach (range(1, 20) as $i) {
        $this->postJson('/api/login', [
            'email' => "victim{$i}",
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/login', [
        'email' => 'victim21',
        'password' => 'wrong-password',
    ])->assertStatus(429);
});

it('lets a legitimate login through', function () {
    User::factory()->create([
        'username' => 'siti',
        'password' => bcrypt('correct-horse-battery'),
    ]);

    $this->postJson('/api/login', [
        'email' => 'siti',
        'password' => 'correct-horse-battery',
    ])->assertOk();
});

it('applies the tighter face limit to attendance events', function () {
    config(['attendance.face.enabled' => false]);
    Setting::set('attendance_break_enabled', false);
    Setting::set('attendance_shift_enabled', false);
    Employee::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    $payload = fn () => [
        'type' => 'check_in',
        'latitude' => -8.6705,
        'longitude' => 115.2126,
        'accuracy' => 15.0,
        'gps_timestamp' => now()->getTimestampMs(),
    ];

    // 12 requests are allowed regardless of whether each one is accepted;
    // the throttle counts requests, not successes.
    foreach (range(1, 12) as $ignored) {
        $response = $this->postJson('/api/attendance/event', $payload());
        expect($response->status())->not->toBe(429);
    }

    $this->postJson('/api/attendance/event', $payload())->assertStatus(429);
});
