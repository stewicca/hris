<?php

namespace App\Http\Controllers\Shifts;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('shifts/index', [
            'shifts' => Shift::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateShift($request);

        Shift::create($validated);

        return back()->with('success', 'Shift ditambahkan.');
    }

    public function update(Request $request, Shift $shift): RedirectResponse
    {
        $validated = $this->validateShift($request, $shift->id);

        $shift->update($validated);

        return back()->with('success', 'Shift diperbarui.');
    }

    public function destroy(Shift $shift): RedirectResponse
    {
        if ($shift->employees()->exists() || $shift->schedules()->exists()) {
            return back()->with('error', 'Shift tidak dapat dihapus karena masih digunakan oleh karyawan atau jadwal.');
        }

        $shift->delete();

        return back()->with('success', 'Shift dihapus.');
    }

    /**
     * Shared validation for store & update. Break window is required only when
     * break tracking is enabled for this shift.
     *
     * @return array<string, mixed>
     */
    private function validateShift(Request $request, ?int $ignoreId = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100', 'unique:shifts,name'.($ignoreId ? ",{$ignoreId}" : '')],
            'check_in' => ['required', 'date_format:H:i'],
            'check_out' => ['required', 'date_format:H:i', 'after:check_in'],
            'late_threshold' => ['required', 'date_format:H:i', 'after_or_equal:check_in'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:480'],
            'break_enabled' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];

        if ($request->boolean('break_enabled')) {
            $rules['break_start'] = ['required', 'date_format:H:i'];
            $rules['break_end'] = ['required', 'date_format:H:i', 'after:break_start'];
        }

        return $request->validate($rules);
    }
}
