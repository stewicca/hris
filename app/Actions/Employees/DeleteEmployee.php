<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteEmployee
{
    public function handle(Employee $employee, ?User $actor = null): void
    {
        abort_if(
            $actor !== null && $employee->user_id !== null && $employee->user_id === $actor->id,
            403,
            'Anda tidak dapat menghapus akun karyawan Anda sendiri.',
        );

        if ($employee->face_photo_path) {
            Storage::disk('local')->delete($employee->face_photo_path);
        }

        DB::transaction(function () use ($employee): void {
            $user = $employee->user;
            $employee->delete();
            $user?->delete();
        });
    }
}
