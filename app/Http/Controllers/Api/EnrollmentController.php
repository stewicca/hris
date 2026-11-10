<?php

namespace App\Http\Controllers\Api;

use App\Actions\Employees\EnrollEmployeeFace;
use App\Actions\Employees\RemoveEmployeeFace;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function store(Request $request, Employee $employee, EnrollEmployeeFace $enroll): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        $employee = $enroll->handle($employee, $validated['image']);

        return response()->json([
            'message' => 'Wajah berhasil didaftarkan.',
            'enrolled_at' => $employee->face_enrolled_at,
        ]);
    }

    public function destroy(Request $request, Employee $employee, RemoveEmployeeFace $remove): JsonResponse
    {
        $this->authorizeAdmin($request);

        $remove->handle($employee);

        return response()->json([
            'message' => 'Pendaftaran wajah dihapus.',
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        if (! $request->user()?->is_admin) {
            abort(403, 'Hanya admin yang dapat mendaftarkan wajah karyawan.');
        }
    }
}
