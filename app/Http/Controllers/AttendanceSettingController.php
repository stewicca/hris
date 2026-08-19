<?php

namespace App\Http\Controllers;

use App\Models\Setting;
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

    /**
     * Persist the work hours (check-in / check-out / late threshold).
     */
    public function updateHours(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'check_in' => ['required', 'date_format:H:i'],
            'check_out' => ['required', 'date_format:H:i', 'after:check_in'],
            'late_threshold' => ['required', 'date_format:H:i'],
        ]);

        Setting::set('office_hours', $validated);

        return back()->with('success', 'Jam kerja berhasil diperbarui.');
    }

    /**
     * Persist the global break window (used when shifts are disabled but the
     * break feature is enabled). Per-shift break windows are configured in the
     * Shift editor instead.
     */
    public function updateBreak(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'break_start' => ['required', 'date_format:H:i'],
            'break_end' => ['required', 'date_format:H:i', 'after:break_start'],
        ]);

        Setting::set('break_window', $validated);

        return back()->with('success', 'Jam istirahat berhasil diperbarui.');
    }

    /**
     * Persist the office geofence location. Submitting without coordinates
     * (or with enable_geofence=false) disables the check.
     */
    public function updateLocation(Request $request): RedirectResponse
    {
        $enabled = (bool) $request->boolean('enable_geofence');

        $validated = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required', 'integer', 'min:10', 'max:5000'],
        ]);

        if ($enabled && $validated['latitude'] !== null && $validated['longitude'] !== null) {
            Setting::set('office_location', [
                'latitude' => (float) $validated['latitude'],
                'longitude' => (float) $validated['longitude'],
                'radius_meters' => (int) $validated['radius_meters'],
            ]);
        } else {
            // Disable geofence but keep the radius for the next time it's enabled.
            Setting::set('office_location', [
                'latitude' => null,
                'longitude' => null,
                'radius_meters' => (int) $validated['radius_meters'],
            ]);
        }

        return back()->with('success', 'Lokasi kantor berhasil diperbarui.');
    }
}
