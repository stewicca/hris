<?php

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['attendance.face.enabled' => true]);

    $this->admin = User::factory()->admin()->create();
    $this->employee = Employee::factory()->create();

    Storage::fake('local');
});

/** Real 1x1 JPEG bytes (GD is unavailable in the test container). */
function enrollmentFaceImage(): UploadedFile
{
    $jpeg = base64_decode(
        '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUHQ8RDREdFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAABAAEDASIAAhEBAxEB/8QAFAABAAAAAAAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AssAB/9k='
    );

    return UploadedFile::fake()->createWithContent('face.jpg', $jpeg);
}

it('allows an admin to enroll a face', function () {
    Http::fake([
        'face-recognition:5000/embed' => Http::response([
            'embedding' => array_fill(0, 512, 0.2),
            'detected' => true,
            'liveness' => 'unknown',
        ]),
    ]);

    $this->actingAs($this->admin)
        ->postJson("/api/employees/{$this->employee->id}/enroll-face", [
            'image' => enrollmentFaceImage(),
        ])
        ->assertOk()
        ->assertJson(['message' => 'Wajah berhasil didaftarkan.']);

    $employee = $this->employee->fresh();
    expect($employee->face_embedding)->toHaveCount(512)
        ->and($employee->face_photo_path)->not->toBeNull()
        ->and($employee->face_enrolled_at)->not->toBeNull()
        ->and($employee->isFaceEnrolled())->toBeTrue();
});

it('rejects enrollment when no face is detected', function () {
    Http::fake([
        'face-recognition:5000/embed' => Http::response([
            'embedding' => null,
            'detected' => false,
            'liveness' => 'unknown',
        ]),
    ]);

    $this->actingAs($this->admin)
        ->postJson("/api/employees/{$this->employee->id}/enroll-face", [
            'image' => enrollmentFaceImage(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    expect($this->employee->fresh()->face_embedding)->toBeNull();
});

it('forbids non-admin users from enrolling', function () {
    $employeeUser = User::factory()->create();

    $this->actingAs($employeeUser)
        ->postJson("/api/employees/{$this->employee->id}/enroll-face", [
            'image' => enrollmentFaceImage(),
        ])
        ->assertForbidden();
});

it('allows an admin to remove an existing enrollment', function () {
    $this->employee->update([
        'face_embedding' => array_fill(0, 512, 0.5),
        'face_photo_path' => 'face-enrollment/old.jpg',
        'face_enrolled_at' => now(),
    ]);
    Storage::disk('local')->put('face-enrollment/old.jpg', 'x');

    $this->actingAs($this->admin)
        ->deleteJson("/api/employees/{$this->employee->id}/enroll-face")
        ->assertOk()
        ->assertJson(['message' => 'Pendaftaran wajah dihapus.']);

    $employee = $this->employee->fresh();
    expect($employee->face_embedding)->toBeNull()
        ->and($employee->face_photo_path)->toBeNull()
        ->and($employee->face_enrolled_at)->toBeNull()
        ->and(Storage::disk('local')->exists('face-enrollment/old.jpg'))->toBeFalse();
});

it('requires an image file for enrollment', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/employees/{$this->employee->id}/enroll-face", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');
});
