<?php

namespace App\Actions\Kiosk;

use App\Actions\Attendance\RecordAttendanceEvent;
use App\AttendanceEventType;
use App\Models\Employee;
use App\Models\KioskDevice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ConfirmKioskScan
{
    public function __construct(private readonly RecordAttendanceEvent $record) {}

    /**
     * Redeem a single-use kiosk scan, persisting its photo to disk only now
     * that the employee has confirmed it.
     *
     * @return array{ok: true, payload: array<string, mixed>}|array{ok: false, reason: string, message: string}
     */
    public function handle(string $scanId, KioskDevice $device): array
    {
        $scan = Cache::pull('kiosk:scan:'.$scanId);

        if ($scan === null) {
            return $this->refuse('scan_expired', 'Sesi pemindaian sudah kedaluwarsa. Silakan pindai ulang.');
        }

        if ($scan['device_id'] !== $device->id) {
            return $this->refuse('scan_foreign_device', 'Sesi pemindaian bukan milik perangkat ini.');
        }

        $employee = Employee::find($scan['employee_id']);

        if ($employee === null) {
            return $this->refuse('employee_missing', 'Data karyawan tidak ditemukan.');
        }

        $photoPath = $scan['photo_path'] ?? null;

        if ($photoPath === null && isset($scan['photo_base64'])) {
            $ext = $scan['photo_ext'] ?? 'jpg';
            $photoPath = 'attendance-photos/'.today()->toDateString().'/'.Str::random(40).'.'.$ext;
            Storage::disk('local')->put($photoPath, (string) base64_decode($scan['photo_base64'], true));
        }

        $type = AttendanceEventType::from($scan['action']);

        $attendance = $this->record->handle(
            employee: $employee,
            requestedType: $type,
            photoPath: $photoPath,
            faceVerified: true,
            notes: 'Kiosk: '.($device->location ?? $device->name),
        );

        return [
            'ok' => true,
            'payload' => [
                'message' => $this->successMessage($type, $employee->name),
                'employee' => ['name' => $employee->name],
                'attendance' => $attendance,
                'next_action' => $attendance->nextExpectedAction(),
            ],
        ];
    }

    /**
     * @return array{ok: false, reason: string, message: string}
     */
    private function refuse(string $reason, string $message): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'message' => $message,
        ];
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
