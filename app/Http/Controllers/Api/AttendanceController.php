<?php

namespace App\Http\Controllers\Api;

use App\Actions\Attendance\RecordAttendanceEvent;
use App\Actions\Attendance\VerifyAttendanceFace;
use App\Actions\Attendance\VerifyAttendanceGps;
use App\AttendanceEventType;
use App\Http\Controllers\Controller;
use App\Support\AttendanceSettings;
use App\Support\FaceVerification;
use App\Support\FeatureSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function today(Request $request, RecordAttendanceEvent $record): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['message' => 'Employee profile not found.'], 404);
        }

        $attendance = $record->activeAttendance($employee)?->load(['events', 'shift']);

        return response()->json([
            'attendance' => $attendance,
            'next_action' => $attendance?->nextExpectedAction(),
        ]);
    }

    public function recordEvent(
        Request $request,
        RecordAttendanceEvent $record,
        VerifyAttendanceGps $verifyGps,
        VerifyAttendanceFace $verifyFace,
    ): JsonResponse {
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

        $record->assertActionIsDue($employee, $requestedType);

        $verifyGps->handle(
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            (float) $validated['accuracy'],
            (int) $validated['gps_timestamp'],
        );

        $face = $verifyFace->handle($request->file('image'), $employee);

        $attendance = $record->handle(
            employee: $employee,
            requestedType: $requestedType,
            photoPath: $face['path'],
            faceVerified: $face['verified'],
            latitude: (float) $validated['latitude'],
            longitude: (float) $validated['longitude'],
            accuracy: (float) $validated['accuracy'],
            notes: $validated['notes'] ?? null,
        );

        return response()->json([
            'message' => $this->successMessage($requestedType),
            'attendance' => $attendance,
            'next_action' => $attendance->nextExpectedAction(),
        ], 201);
    }

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
        ]);
    }

    /**
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

    private function successMessage(AttendanceEventType $type): string
    {
        return match ($type) {
            AttendanceEventType::CheckIn => 'Check-in berhasil.',
            AttendanceEventType::BreakStart => 'Istirahat dimulai.',
            AttendanceEventType::BreakEnd => 'Istirahat berakhir.',
            AttendanceEventType::CheckOut => 'Check-out berhasil.',
        };
    }
}
