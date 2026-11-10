<?php

namespace App\Http\Controllers\Shifts;

use App\Actions\Shifts\DeleteShift;
use App\Actions\Shifts\SaveShift;
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

    public function store(Request $request, SaveShift $saveShift): RedirectResponse
    {
        $saveShift->handle($request);

        return back()->with('success', 'Shift ditambahkan.');
    }

    public function update(Request $request, Shift $shift, SaveShift $saveShift): RedirectResponse
    {
        $saveShift->handle($request, $shift);

        return back()->with('success', 'Shift diperbarui.');
    }

    public function destroy(Shift $shift, DeleteShift $deleteShift): RedirectResponse
    {
        if (! $deleteShift->handle($shift)) {
            return back()->with('error', 'Shift tidak dapat dihapus karena masih digunakan oleh karyawan atau jadwal.');
        }

        return back()->with('success', 'Shift dihapus.');
    }
}
