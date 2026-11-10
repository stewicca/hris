<?php

namespace App\Http\Controllers;

use App\Actions\Settings\CreateHoliday;
use App\Actions\Settings\DeleteHoliday;
use App\Actions\Settings\UpdateWorkingDays;
use App\Models\Holiday;
use App\Support\WorkCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkCalendarController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('work-calendar/index', [
            'workingDays' => WorkCalendar::workingDays(),
            'holidays' => Holiday::orderBy('date')->get(['id', 'date', 'name']),
        ]);
    }

    public function update(Request $request, UpdateWorkingDays $updateWorkingDays): RedirectResponse
    {
        $validated = $request->validate([
            'working_days' => ['required', 'array'],
            'working_days.*' => ['integer', 'between:1,7'],
        ]);

        $updateWorkingDays->handle($validated['working_days']);

        return back()->with('success', 'Hari kerja diperbarui.');
    }

    public function storeHoliday(Request $request, CreateHoliday $createHoliday): RedirectResponse
    {
        $validated = $request->validate([
            'date' => [
                'required',
                'date',
                function (string $attribute, string $value, \Closure $fail): void {
                    if (Holiday::whereDate('date', $value)->exists()) {
                        $fail('Tanggal libur ini sudah ada.');
                    }
                },
            ],
            'name' => ['required', 'string', 'max:100'],
        ]);

        $createHoliday->handle($validated);

        return back()->with('success', 'Hari libur ditambahkan.');
    }

    public function destroyHoliday(Holiday $holiday, DeleteHoliday $deleteHoliday): RedirectResponse
    {
        $deleteHoliday->handle($holiday);

        return back()->with('success', 'Hari libur dihapus.');
    }
}
