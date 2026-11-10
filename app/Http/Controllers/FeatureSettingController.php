<?php

namespace App\Http\Controllers;

use App\Actions\Settings\UpdateFeatureSettings;
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

    public function update(Request $request, UpdateFeatureSettings $updateFeatures): RedirectResponse
    {
        $validated = $request->validate([
            'leave_enabled' => ['required', 'boolean'],
            'attendance_break_enabled' => ['required', 'boolean'],
            'attendance_shift_enabled' => ['required', 'boolean'],
            'payroll_enabled' => ['required', 'boolean'],
            'kiosk_enabled' => ['required', 'boolean'],
        ]);

        $updateFeatures->handle($validated);

        return back()->with('success', 'Pengaturan fitur berhasil diperbarui.');
    }
}
