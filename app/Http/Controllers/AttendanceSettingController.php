<?php

namespace App\Http\Controllers;

use App\Actions\Settings\UpdateAttendanceBreak;
use App\Actions\Settings\UpdateAttendanceHours;
use App\Actions\Settings\UpdateOfficeLocation;
use App\Support\AttendanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceSettingController extends Controller
{
    public function index(): Response
    {
        $location = AttendanceSettings::officeLocation();

        return Inertia::render('attendance-settings/index', [
            'officeHours' => AttendanceSettings::officeHours(),
            'officeLocation' => $location,
            'geofenceEnabled' => $location['latitude'] !== null && $location['longitude'] !== null,
            'breakWindow' => AttendanceSettings::breakWindow(),
        ]);
    }

    public function updateHours(Request $request, UpdateAttendanceHours $updateHours): RedirectResponse
    {
        $validated = $request->validate([
            'check_in' => ['required', 'date_format:H:i'],
            'check_out' => ['required', 'date_format:H:i', 'after:check_in'],
            'late_threshold' => ['required', 'date_format:H:i'],
        ]);

        $updateHours->handle($validated);

        return back()->with('success', 'Jam kerja berhasil diperbarui.');
    }

    public function updateBreak(Request $request, UpdateAttendanceBreak $updateBreak): RedirectResponse
    {
        $validated = $request->validate([
            'break_start' => ['required', 'date_format:H:i'],
            'break_end' => ['required', 'date_format:H:i', 'after:break_start'],
        ]);

        $updateBreak->handle($validated);

        return back()->with('success', 'Jam istirahat berhasil diperbarui.');
    }

    public function updateLocation(Request $request, UpdateOfficeLocation $updateLocation): RedirectResponse
    {
        $validated = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required', 'integer', 'min:10', 'max:5000'],
        ]);

        $updateLocation->handle($request->boolean('enable_geofence'), $validated);

        return back()->with('success', 'Lokasi kantor berhasil diperbarui.');
    }
}
