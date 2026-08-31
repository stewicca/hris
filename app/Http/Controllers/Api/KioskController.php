<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordAttendanceEvent;
use App\AttendanceEventType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureValidKioskDevice;
use App\Models\Employee;
use App\Models\KioskDevice;
use App\Support\AttendanceSettings;
use App\Support\FaceMatcher;
use App\Support\FaceVerification;
use App\Support\FeatureSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The unattended attendance terminal.
 *
 * Nobody logs in here. Identity comes from the face (1:N, see {@see FaceMatcher})
 * and provenance from the device token ({@see EnsureValidKioskDevice}).
 *
 * A scan is deliberately two requests rather than one. The first identifies and
 * answers "who is this, and what are they due to do?"; the second records it,
 * after the person has confirmed the name on screen. On a 1:N match that
 * confirmation is worth the extra tap — a check-in filed against the wrong
 * employee is considerably more work to unpick than a repeated scan.
 *
 * Between the two, the result lives server-side under a single-use scan id.
 * The client never gets to name an employee; if it could, a terminal could file
 * attendance for anyone on the roster without a face at all.
 */
class KioskController extends Controller
{
    public function __construct(private readonly RecordAttendanceEvent $record) {}

    /**
     * What the terminal needs to render itself, before anyone steps up.
     */
    public function settings(Request $request): JsonResponse
    {
        return response()->json([
            'device' => [
                'name' => $this->device($request)->name,
                'location' => $this->device($request)->location,
            ],
            'office_hours' => AttendanceSettings::officeHours(),
            'break_enabled' => FeatureSettings::breakEnabled(),
            'shift_enabled' => FeatureSettings::shiftEnabled(),
            // The terminal shows "sedang offline" rather than inviting someone
            // to pose for a scan that cannot succeed.
            'face_service_operational' => FaceVerification::isOperational(),
        ]);
    }

    /**
     * Identify the face in front of the camera and report what they are due to
     * do. Records nothing.
     */
    public function identify(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        if (! FaceVerification::isEnabled()) {
            return $this->refuse('face_disabled', 'Verifikasi wajah sedang nonaktif. Gunakan aplikasi karyawan.');
        }

        $path = $request->file('image')->store('attendance-photos/'.today()->toDateString(), 'local');

        $match = FaceMatcher::identify(Storage::disk('local')->path($path));

        if ($match['employee'] === null) {
            Storage::disk('local')->delete($path);

            return $this->refuse($match['reason'], $this->reasonMessage($match['reason']));
        }

        $employee = $match['employee'];
        $nextAction = $this->record->nextActionFor($employee);

        if ($nextAction === null) {
            Storage::disk('local')->delete($path);

            return $this->refuse(
                'already_complete',
                "Halo {$employee->name}, absensi Anda hari ini sudah selesai.",
            );
        }

        $scanId = (string) Str::ulid();
        $ttl = (int) config('attendance.kiosk.scan_ttl_seconds');

        Cache::put($this->scanKey($scanId), [
            'employee_id' => $employee->id,
            'device_id' => $this->device($request)->id,
            'photo_path' => $path,
            'action' => $nextAction->value,
        ], $ttl);

        return response()->json([
            'scan_id' => $scanId,
            'expires_in' => $ttl,
            'employee' => [
                'name' => $employee->name,
                'employee_number' => $employee->employee_number,
                'department' => $employee->department?->name,
            ],
            'next_action' => $nextAction,
            'prompt' => $this->actionPrompt($nextAction),
        ]);
    }

    /**
     * Commit the scan the person just confirmed on screen.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scan_id' => ['required', 'string'],
        ]);

        // pull() is get-and-forget: a scan is single use, so a double tap or a
        // retried request cannot record the same face twice.
        $scan = Cache::pull($this->scanKey($validated['scan_id']));

        if ($scan === null) {
            return $this->refuse('scan_expired', 'Sesi pemindaian sudah kedaluwarsa. Silakan pindai ulang.');
        }

        // A scan is redeemable only at the terminal that produced it, so a
        // token lifted from one device cannot spend another device's scans.
        if ($scan['device_id'] !== $this->device($request)->id) {
            return $this->refuse('scan_foreign_device', 'Sesi pemindaian bukan milik perangkat ini.');
        }

        $employee = Employee::find($scan['employee_id']);

        if ($employee === null) {
            return $this->refuse('employee_missing', 'Data karyawan tidak ditemukan.');
        }

        $type = AttendanceEventType::from($scan['action']);
        $device = $this->device($request);

        $attendance = $this->record->handle(
            employee: $employee,
            requestedType: $type,
            photoPath: $scan['photo_path'],
            faceVerified: true,
            notes: 'Kiosk: '.($device->location ?? $device->name),
        );

        return response()->json([
            'message' => $this->successMessage($type, $employee->name),
            'employee' => ['name' => $employee->name],
            'attendance' => $attendance,
            'next_action' => $attendance->nextExpectedAction(),
        ], 201);
    }

    private function device(Request $request): KioskDevice
    {
        return $request->attributes->get(EnsureValidKioskDevice::ATTRIBUTE);
    }

    private function scanKey(string $scanId): string
    {
        return 'kiosk:scan:'.$scanId;
    }

    /**
     * A refusal the terminal can act on: `reason` drives the on-screen state,
     * `message` is what the person standing there reads.
     */
    private function refuse(?string $reason, string $message): JsonResponse
    {
        return response()->json([
            'reason' => $reason,
            'message' => $message,
        ], 422);
    }

    private function reasonMessage(?string $reason): string
    {
        return match ($reason) {
            'service_unavailable' => 'Layanan pengenalan wajah sedang tidak tersedia. Hubungi admin.',
            'spoof' => 'Terdeteksi foto atau video. Absensi menggunakan foto tidak diperbolehkan.',
            'not_detected' => 'Wajah tidak terdeteksi. Posisikan wajah di dalam bingkai dan hadap kamera.',
            'no_enrolled_faces' => 'Belum ada karyawan yang wajahnya terdaftar.',
            'ambiguous' => 'Wajah Anda mirip dengan lebih dari satu karyawan. Coba lagi dengan pencahayaan yang lebih baik.',
            default => 'Wajah tidak dikenali. Pastikan wajah Anda sudah didaftarkan oleh admin.',
        };
    }

    private function actionPrompt(AttendanceEventType $type): string
    {
        return match ($type) {
            AttendanceEventType::CheckIn => 'Check-in sekarang?',
            AttendanceEventType::BreakStart => 'Mulai istirahat?',
            AttendanceEventType::BreakEnd => 'Selesai istirahat?',
            AttendanceEventType::CheckOut => 'Check-out sekarang?',
        };
    }

    private function successMessage(AttendanceEventType $type, string $name): string
    {
        return match ($type) {
            AttendanceEventType::CheckIn => "Check-in berhasil. Selamat bekerja, {$name}!",
            AttendanceEventType::BreakStart => "Istirahat dimulai. Selamat istirahat, {$name}!",
            AttendanceEventType::BreakEnd => "Istirahat berakhir. Semangat lagi, {$name}!",
            AttendanceEventType::CheckOut => "Check-out berhasil. Hati-hati di jalan, {$name}!",
        };
    }
}
