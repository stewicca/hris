<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use Illuminate\Support\Facades\Storage;

class RemoveEmployeeFace
{
    public function handle(Employee $employee): void
    {
        if ($employee->face_photo_path) {
            Storage::disk('local')->delete($employee->face_photo_path);
        }

        $employee->update([
            'face_embedding' => null,
            'face_photo_path' => null,
            'face_enrolled_at' => null,
        ]);
    }
}
