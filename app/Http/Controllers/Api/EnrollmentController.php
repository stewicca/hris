<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\FaceVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Admin-only endpoints for enrolling an employee's reference face.
 *
 * The reference embedding is stored on the employee record and later compared
 * against probe photos at check-in/out. Only admin users may enroll — letting
 * employees self-enroll would let them register someone else's face.
 */
class EnrollmentController extends Controller
{
    /**
     * Capture (or re-capture) the reference face for an employee.
     */
    public function store(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        if (! FaceVerification::isEnabled()) {
            throw ValidationException::withMessages([
                'image' => ['Verifikasi wajah sedang nonaktif.'],
            ]);
        }

        $path = $validated['image']->store('face-enrollment', 'local');
        $absolute = Storage::disk('local')->path($path);

        try {
            $result = FaceVerification::embed($absolute);
        } finally {
            // The reference photo is kept only for audit; the embedding is what
            // we actually compare. Store it next to the embedding.
        }

        if (! $result['detected'] || empty($result['embedding'])) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages([
                'image' => ['Wajah tidak terdeteksi pada foto. Ambil foto dengan pencahayaan yang cukup dan menghadap kamera langsung.'],
            ]);
        }

        // Replace the previous reference photo if re-enrolling.
        if ($employee->face_photo_path) {
            Storage::disk('local')->delete($employee->face_photo_path);
        }

        $employee->update([
            'face_embedding' => $result['embedding'],
            'face_photo_path' => $path,
            'face_enrolled_at' => now(),
        ]);

        return response()->json([
            'message' => 'Wajah berhasil didaftarkan.',
            'enrolled_at' => $employee->fresh()->face_enrolled_at,
        ]);
    }

    /**
     * Remove the reference face (disables face-based checks for the employee).
     */
    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeAdmin($request);

        if ($employee->face_photo_path) {
            Storage::disk('local')->delete($employee->face_photo_path);
        }

        $employee->update([
            'face_embedding' => null,
            'face_photo_path' => null,
            'face_enrolled_at' => null,
        ]);

        return response()->json([
            'message' => 'Pendaftaran wajah dihapus.',
        ]);
    }

    /**
     * Reject non-admin users. PWA users reach /api via the session guard, so we
     * check the linked user's is_admin flag.
     */
    private function authorizeAdmin(Request $request): void
    {
        if (! $request->user()?->is_admin) {
            abort(403, 'Hanya admin yang dapat mendaftarkan wajah karyawan.');
        }
    }
}
