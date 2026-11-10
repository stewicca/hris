<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Support\FaceVerification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EnrollEmployeeFace
{
    public function handle(Employee $employee, UploadedFile $image): Employee
    {
        if (! FaceVerification::isEnabled()) {
            throw ValidationException::withMessages([
                'image' => ['Verifikasi wajah sedang nonaktif.'],
            ]);
        }

        $path = $image->store('face-enrollment', 'local');
        $absolute = Storage::disk('local')->path($path);

        $result = FaceVerification::embed($absolute);

        if (! $result['detected'] || empty($result['embedding'])) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages([
                'image' => ['Wajah tidak terdeteksi pada foto. Ambil foto dengan pencahayaan yang cukup dan menghadap kamera langsung.'],
            ]);
        }

        if ($employee->face_photo_path) {
            Storage::disk('local')->delete($employee->face_photo_path);
        }

        $employee->update([
            'face_embedding' => $result['embedding'],
            'face_photo_path' => $path,
            'face_enrolled_at' => now(),
        ]);

        return $employee->fresh();
    }
}
