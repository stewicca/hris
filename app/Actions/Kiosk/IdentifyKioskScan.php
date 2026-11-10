<?php

namespace App\Actions\Kiosk;

use App\Actions\Attendance\RecordAttendanceEvent;
use App\AttendanceEventType;
use App\Models\KioskDevice;
use App\Support\FaceMatcher;
use App\Support\FaceVerification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class IdentifyKioskScan
{
    public function __construct(private readonly RecordAttendanceEvent $record) {}

    /**
     * Identify a kiosk face scan without persisting the photo to disk until the
     * employee confirms the scan.
     *
     * @return array{ok: true, payload: array<string, mixed>}|array{ok: false, reason: string|null, message: string}
     */
    public function handle(UploadedFile $image, KioskDevice $device): array
    {
        if (! FaceVerification::isEnabled()) {
            return $this->refuse('face_disabled', 'Verifikasi wajah sedang nonaktif. Gunakan aplikasi karyawan.');
        }

        $realPath = $image->getRealPath();

        if (! is_string($realPath) || $realPath === '') {
            return $this->refuse('not_detected', $this->reasonMessage('not_detected'));
        }

        $match = FaceMatcher::identify($realPath);

        if ($match['employee'] === null) {
            return $this->refuse($match['reason'], $this->reasonMessage($match['reason']));
        }

        $employee = $match['employee'];
        $nextAction = $this->record->nextActionFor($employee);

        if ($nextAction === null) {
            return $this->refuse(
                'already_complete',
                "Halo {$employee->name}, absensi Anda hari ini sudah selesai.",
            );
        }

        $scanId = (string) Str::ulid();
        $ttl = (int) config('attendance.kiosk.scan_ttl_seconds');
        $extension = $image->guessExtension() ?: 'jpg';

        Cache::put('kiosk:scan:'.$scanId, [
            'employee_id' => $employee->id,
            'device_id' => $device->id,
            'photo_base64' => base64_encode((string) file_get_contents($realPath)),
            'photo_ext' => $extension,
            'action' => $nextAction->value,
        ], $ttl);

        return [
            'ok' => true,
            'payload' => [
                'scan_id' => $scanId,
                'expires_in' => $ttl,
                'employee' => [
                    'name' => $employee->name,
                    'employee_number' => $employee->employee_number,
                    'department' => $employee->department?->name,
                ],
                'next_action' => $nextAction,
                'prompt' => $this->actionPrompt($nextAction),
            ],
        ];
    }

    /**
     * @return array{ok: false, reason: string|null, message: string}
     */
    private function refuse(?string $reason, string $message): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'message' => $message,
        ];
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
}
