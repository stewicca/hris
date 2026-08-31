<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordAttendanceEvent;
use App\AttendanceEventType;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\AttendanceSettings;
use App\Support\FaceVerification;
use App\Support\FeatureSettings;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    /**
     * Get today's attendance record for the authenticated employee.
     */
    public function today(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['message' => 'Employee profile not found.'], 404);
        }

        $attendance = $employee->attendances()
            ->whereDate('date', today())
            ->with(['events', 'shift'])
            ->first();

        return response()->json([
            'attendance' => $attendance,
            'next_action' => $attendance?->nextExpectedAction(),
        ]);
    }

    /**
     * Record the next attendance event (check_in / break_start / break_end /
     * check_out). The expected event is derived from the current timeline
     * state, so the client only submits a single action and the server decides
     * what comes next — the user is never asked "which kind of attendance".
     *
     * Break is optional: from "checked in, no break" the next action is
     * break_start, but the client may send check_out directly to skip it.
     */
    public function recordEvent(Request $request, RecordAttendanceEvent $record): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['message' => 'Employee profile not found.'], 404);
        }

        $validated = $request->validate([
            'type' => ['required', 'in:check_in,break_start,break_end,check_out'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0'],
            'gps_timestamp' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
            'image' => $this->imageRules(),
        ]);

        $requestedType = AttendanceEventType::from($validated['type']);

        // Cheapest, most diagnostic failure first: a request for an action the
        // timeline is not waiting for is refused before any GPS maths or a
        // CPU-bound trip to the face service.
        $record->assertActionIsDue($employee, $requestedType);

        $this->validateGpsIntegrity($validated['accuracy'], $validated['gps_timestamp']);
        $this->validateGeofence($validated['latitude'], $validated['longitude']);

        // Face verification runs after GPS so a geofence/accuracy failure is
        // reported first (cheaper and more diagnostic).
        $face = $this->verifyFace($request, $employee);

        $attendance = $record->handle(
            employee: $employee,
            requestedType: $requestedType,
            photoPath: $face['path'],
            faceVerified: $face['verified'],
            latitude: $validated['latitude'],
            longitude: $validated['longitude'],
            accuracy: $validated['accuracy'],
            notes: $validated['notes'] ?? null,
        );

        return response()->json([
            'message' => $this->successMessage($requestedType),
            'attendance' => $attendance,
            'next_action' => $attendance->nextExpectedAction(),
        ], 201);
    }

    private function successMessage(AttendanceEventType $type): string
    {
        return match ($type) {
            AttendanceEventType::CheckIn => 'Check-in berhasil.',
            AttendanceEventType::BreakStart => 'Istirahat dimulai.',
            AttendanceEventType::BreakEnd => 'Istirahat berakhir.',
            AttendanceEventType::CheckOut => 'Check-out berhasil.',
        };
    }

    /**
     * Get recent attendance history (last 30 days).
     */
    public function history(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['message' => 'Employee profile not found.'], 404);
        }

        $records = $employee->attendances()
            ->whereBetween('date', [now()->subDays(30), today()])
            ->orderByDesc('date')
            ->get();

        return response()->json([
            'history' => $records,
        ]);
    }

    /**
     * Public configuration consumed by the employee app (office hours,
     * geofence status, feature toggles & the caller's shift) so the client can
     * display the right UI without asking the user anything.
     */
    public function settings(Request $request): JsonResponse
    {
        $location = AttendanceSettings::officeLocation();
        $employee = $request->user()?->employee;
        $shift = $employee
            ? AttendanceSettings::resolveShift($employee, today())
            : null;

        return response()->json([
            'office_hours' => AttendanceSettings::officeHours(),
            'geofence_enabled' => $location['latitude'] !== null && $location['longitude'] !== null,
            'radius_meters' => $location['radius_meters'],
            'leave_enabled' => FeatureSettings::leaveEnabled(),
            'break_enabled' => FeatureSettings::breakEnabled(),
            'shift_enabled' => FeatureSettings::shiftEnabled(),
            'payroll_enabled' => FeatureSettings::payrollEnabled(),
            'shift' => $shift,
            // Coordinates are intentionally NOT exposed to clients to avoid
            // leaking the exact office location; geofencing stays server-side.
        ]);
    }

    /**
     * Validation rules for the face image. Required when face recognition is
     * enabled, optional otherwise (so a GPS-only fallback still works).
     *
     * @return array<int, string>
     */
    private function imageRules(): array
    {
        $rules = ['image', 'mimes:jpg,jpeg,png', 'max:5120'];

        if (FaceVerification::isEnabled()) {
            array_unshift($rules, 'required');
        } else {
            array_unshift($rules, 'nullable');
        }

        return $rules;
    }

    /**
     * Verify the submitted selfie against the employee's enrolled reference.
     *
     * Returns a {path, verified} tuple. When face recognition is disabled the
     * photo is still stored (when provided) for audit but verified is false.
     *
     * @return array{path: string|null, verified: bool}
     *
     * @throws ValidationException When verification fails or the employee is
     *                             not enrolled (and enrollment is required).
     */
    private function verifyFace(Request $request, Employee $employee): array
    {
        $enabled = FaceVerification::isEnabled();
        $image = $request->file('image');

        if (! $enabled) {
            // Fallback: store the photo if the client sent one, otherwise skip.
            return [
                'path' => $image?->store('attendance-photos/'.today()->toDateString(), 'local'),
                'verified' => false,
            ];
        }

        if ($image === null) {
            throw ValidationException::withMessages([
                'image' => ['Foto wajah wajib disertakan saat verifikasi wajah aktif.'],
            ]);
        }

        if (! $employee->isFaceEnrolled()) {
            if (config('attendance.face.require_enrollment', true)) {
                throw ValidationException::withMessages([
                    'face' => ['Wajah Anda belum terdaftar. Hubungi admin untuk mendaftarkan wajah.'],
                ]);
            }

            // Soft mode: not enrolled, capture-only.
            return [
                'path' => $image->store('attendance-photos/'.today()->toDateString(), 'local'),
                'verified' => false,
            ];
        }

        $path = $image->store('attendance-photos/'.today()->toDateString(), 'local');
        $absolute = Storage::disk('local')->path($path);

        $result = FaceVerification::verify($absolute, $employee->face_embedding ?? []);

        if (! $result['detected']) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages([
                'image' => ['Wajah tidak terdeteksi. Pastikan wajah terlihat jelas dan menghadap kamera.'],
            ]);
        }

        if ($result['liveness'] === 'spoof') {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages([
                'image' => ['Terdeteksi foto/video. Absensi menggunakan foto tidak diperbolehkan.'],
            ]);
        }

        if (! $result['verified']) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages([
                'image' => ['Verifikasi wajah gagal. Wajah tidak cocok dengan data terdaftar. Coba lagi.'],
            ]);
        }

        return ['path' => $path, 'verified' => true];
    }

    /**
     * Reject if the submitted coordinates are outside the office geofence.
     * Skipped entirely when no office location is configured.
     */
    private function validateGeofence(float $latitude, float $longitude): void
    {
        $office = AttendanceSettings::officeLocation();

        if ($office['latitude'] === null || $office['longitude'] === null) {
            return;
        }

        $radius = $office['radius_meters'];
        $distance = $this->haversineMeters($latitude, $longitude, $office['latitude'], $office['longitude']);

        if ($distance > $radius) {
            throw ValidationException::withMessages([
                'location' => [
                    sprintf(
                        'Anda berada %.0f meter dari kantor. Absensi hanya bisa dilakukan dalam radius %.0f meter.',
                        $distance,
                        $radius,
                    ),
                ],
            ]);
        }
    }

    /**
     * Validate GPS data integrity to prevent fake/mocked locations.
     *
     * Checks:
     * - Accuracy must be within an acceptable range (real GPS is 3–50m).
     * - GPS timestamp must be recent (prevents replay with cached coordinates).
     */
    private function validateGpsIntegrity(float $accuracy, int $gpsTimestampMs): void
    {
        $maxAccuracy = config('attendance.gps.max_accuracy_meters');

        if ($accuracy > $maxAccuracy) {
            throw ValidationException::withMessages([
                'accuracy' => [
                    "Akurasi GPS terlalu rendah ({$accuracy}m). Pastikan GPS aktif dan berada di luar ruangan.",
                ],
            ]);
        }

        $maxAge = config('attendance.gps.max_age_seconds');
        $gpsAgeSeconds = abs(now()->diffInSeconds(Carbon::createFromTimestampMs($gpsTimestampMs)));

        if ($gpsAgeSeconds > $maxAge) {
            throw ValidationException::withMessages([
                'gps_timestamp' => [
                    'Data lokasi sudah kedaluwarsa. Refresh lokasi GPS Anda dan coba lagi.',
                ],
            ]);
        }
    }

    /**
     * Calculate the great-circle distance between two coordinates (Haversine formula).
     * Returns distance in meters.
     */
    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
