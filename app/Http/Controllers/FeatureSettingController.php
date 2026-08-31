<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\FeatureSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeatureSettingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('feature-settings/index', [
            'leaveEnabled' => FeatureSettings::leaveEnabled(),
            'breakEnabled' => FeatureSettings::breakEnabled(),
            'shiftEnabled' => FeatureSettings::shiftEnabled(),
            'payrollEnabled' => FeatureSettings::payrollEnabled(),
            'kioskEnabled' => FeatureSettings::kioskEnabled(),
        ]);
    }

    /**
     * Persist the feature toggles.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'leave_enabled' => ['required', 'boolean'],
            'attendance_break_enabled' => ['required', 'boolean'],
            'attendance_shift_enabled' => ['required', 'boolean'],
            'payroll_enabled' => ['required', 'boolean'],
            'kiosk_enabled' => ['required', 'boolean'],
        ]);

        Setting::set('leave_enabled', (bool) $validated['leave_enabled']);
        Setting::set('attendance_break_enabled', (bool) $validated['attendance_break_enabled']);
        Setting::set('attendance_shift_enabled', (bool) $validated['attendance_shift_enabled']);
        Setting::set('payroll_enabled', (bool) $validated['payroll_enabled']);
        Setting::set('kiosk_enabled', (bool) $validated['kiosk_enabled']);

        return back()->with('success', 'Pengaturan fitur berhasil diperbarui.');
    }
}
