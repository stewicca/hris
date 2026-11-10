<?php

namespace App\Actions\Attendance;

use App\Models\Employee;
use App\Support\FaceVerification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class VerifyAttendanceFace
{
    /**
     * @return array{path: string|null, verified: bool}
     */
    public function handle(?UploadedFile $image, Employee $employee): array
    {
        $enabled = FaceVerification::isEnabled();

        if (! $enabled) {
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
}
