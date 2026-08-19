<?php

use App\Http\Middleware\LogApiRequests;
use App\Models\ApiLog;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::any('/api/test-log', fn () => response()->json(['hello' => 'world']));
    Route::get('/api/test-log/error', fn () => response()->json(['error' => 'nope'], 422));
    Route::get('/non-api/page', fn () => response('html', 200));
});

it('logs a JSON POST request with its body', function () {
    $payload = ['employee_id' => 'EMP0001', 'type' => 'in'];

    $this->postJson('/api/test-log', $payload)->assertOk();

    $log = ApiLog::firstOrFail();
    expect($log->method)->toBe('POST')
        ->and($log->path)->toBe('api/test-log')
        ->and($log->status_code)->toBe(200)
        ->and($log->source_ip)->not->toBeNull()
        ->and(json_decode($log->request_body, true))->toMatchArray($payload)
        ->and($log->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('does not keep the response body of a successful request', function () {
    // Halving the bytes written per request is what keeps a flood of /api
    // traffic from filling the disk. Failures still keep theirs — see below.
    $this->postJson('/api/test-log', ['type' => 'in'])->assertOk();

    expect(ApiLog::firstOrFail()->response_body)->toBeNull();
});

it('logs a GET request with query params', function () {
    $this->getJson('/api/test-log?device=fingerprint&office=jakarta')->assertOk();

    $log = ApiLog::firstOrFail();
    expect($log->method)->toBe('GET')
        ->and($log->query_params)->toMatchArray(['device' => 'fingerprint', 'office' => 'jakarta']);
});

it('logs a request with form-encoded content type', function () {
    $this->post('/api/test-log', ['pin' => '1234', 'device_id' => 'FP-001'], [
        'Content-Type' => 'application/x-www-form-urlencoded',
    ])->assertOk();

    $log = ApiLog::firstOrFail();
    expect($log->method)->toBe('POST')
        ->and($log->content_type)->toContain('application/x-www-form-urlencoded');
});

it('logs the user agent, content type, and headers', function () {
    $this->withHeaders([
        'User-Agent' => 'ZKTeco-Device/1.0',
        'Content-Type' => 'application/json',
        'X-Device-Serial' => 'SN-ABC123',
    ])->postJson('/api/test-log', ['uid' => '42'])->assertOk();

    $log = ApiLog::firstOrFail();
    expect($log->user_agent)->toBe('ZKTeco-Device/1.0')
        ->and($log->content_type)->toContain('application/json')
        ->and($log->request_headers)->toHaveKey('x-device-serial');
});

it('logs non-2xx responses with the correct status code', function () {
    $this->getJson('/api/test-log/error')->assertStatus(422);

    $log = ApiLog::firstOrFail();
    expect($log->status_code)->toBe(422)
        ->and(json_decode($log->response_body, true))->toBe(['error' => 'nope']);
});

it('logs requests to unknown api routes (404)', function () {
    $this->getJson('/api/this-does-not-exist')->assertStatus(404);

    $log = ApiLog::firstOrFail();
    expect($log->path)->toBe('api/this-does-not-exist')
        ->and($log->status_code)->toBe(404);
});

it('does not log non-api routes', function () {
    $this->get('/non-api/page')->assertOk();

    expect(ApiLog::count())->toBe(0);
});

it('truncates very large request bodies', function () {
    $reflection = new ReflectionClass(LogApiRequests::class);
    $max = $reflection->getConstant('MAX_BODY_BYTES');

    $bigPayload = ['data' => str_repeat('x', $max + 5_000)];

    $this->postJson('/api/test-log', $bigPayload)->assertOk();

    $log = ApiLog::firstOrFail();
    expect($log->request_body)->toEndWith('...[truncated]')
        ->and(strlen($log->request_body))->toBeLessThanOrEqual($max + strlen('...[truncated]'));
});
