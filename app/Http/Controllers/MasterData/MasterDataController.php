<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Position;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MasterDataController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('master-data/index', [
            'departments' => Department::withCount('employees')->orderBy('name')->get(),
            'positions' => Position::withCount('employees')->orderBy('name')->get(),
        ]);
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:departments,name'],
        ]);

        Department::create(['name' => $request->name]);

        return back()->with('success', 'Departemen ditambahkan.');
    }

    public function destroyDepartment(Department $department): RedirectResponse
    {
        $department->delete();

        return back()->with('success', 'Departemen dihapus.');
    }

    public function storePosition(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:positions,name'],
        ]);

        Position::create(['name' => $request->name]);

        return back()->with('success', 'Jabatan ditambahkan.');
    }

    public function destroyPosition(Position $position): RedirectResponse
    {
        $position->delete();

        return back()->with('success', 'Jabatan dihapus.');
    }
}
