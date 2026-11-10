<?php

namespace App\Http\Controllers\Api;

use App\Actions\Kiosk\ConfirmKioskScan;
use App\Actions\Kiosk\IdentifyKioskScan;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureValidKioskDevice;
use App\Models\KioskDevice;
use App\Support\AttendanceSettings;
use App\Support\FaceVerification;
use App\Support\FeatureSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KioskController extends Controller
{
    public function settings(Request $request): JsonResponse
    {
        $device = $this->device($request);

        return response()->json([
            'device' => [
                'name' => $device->name,
                'location' => $device->location,
            ],
            'office_hours' => AttendanceSettings::officeHours(),
            'break_enabled' => FeatureSettings::breakEnabled(),
            'shift_enabled' => FeatureSettings::shiftEnabled(),
            'face_service_operational' => FaceVerification::isOperational(),
        ]);
    }

    public function identify(Request $request, IdentifyKioskScan $identify): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        $result = $identify->handle($request->file('image'), $this->device($request));

        if (! $result['ok']) {
            return response()->json([
                'reason' => $result['reason'],
                'message' => $result['message'],
            ], 422);
        }

        return response()->json($result['payload']);
    }

    public function store(Request $request, ConfirmKioskScan $confirm): JsonResponse
    {
        $validated = $request->validate([
            'scan_id' => ['required', 'string'],
        ]);

        $result = $confirm->handle($validated['scan_id'], $this->device($request));

        if (! $result['ok']) {
            return response()->json([
                'reason' => $result['reason'],
                'message' => $result['message'],
            ], 422);
        }

        return response()->json($result['payload'], 201);
    }

    private function device(Request $request): KioskDevice
    {
        return $request->attributes->get(EnsureValidKioskDevice::ATTRIBUTE);
    }
}
