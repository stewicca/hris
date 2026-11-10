<?php

namespace App\Http\Controllers\Leave;

use App\Actions\Leaves\ApproveLeave;
use App\Actions\Leaves\CancelLeave;
use App\Actions\Leaves\RejectLeave;
use App\Actions\Leaves\SubmitLeave;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leaves\RejectLeaveRequest;
use App\Http\Requests\Leaves\StoreLeaveRequest;
use App\Models\Employee;
use App\Models\Leave;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeaveController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->value() ?: null;
        $type = $request->string('type')->value() ?: null;
        $employeeId = $request->integer('employee_id') ?: null;

        $leaves = Leave::query()
            ->with('employee')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $employees = Employee::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'employee_number']);

        return Inertia::render('leaves/index', [
            'leaves' => $leaves,
            'employees' => $employees,
            'filters' => [
                'status' => $status,
                'type' => $type,
                'employee_id' => $employeeId,
            ],
        ]);
    }

    public function create(): Response
    {
        $employees = Employee::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'employee_number']);

        return Inertia::render('leaves/create', [
            'employees' => $employees,
        ]);
    }

    public function store(StoreLeaveRequest $request, SubmitLeave $submitLeave): RedirectResponse
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));
        $submitLeave->handle($employee, $request->validated());

        return to_route('leaves.index')->with('success', 'Pengajuan cuti berhasil disimpan.');
    }

    public function show(Leave $leave): Response
    {
        $leave->load(['employee', 'approvedBy']);

        return Inertia::render('leaves/show', [
            'leave' => $leave,
        ]);
    }

    public function destroy(Leave $leave, CancelLeave $cancelLeave): RedirectResponse
    {
        $cancelLeave->handle($leave);

        return to_route('leaves.index')->with('success', 'Pengajuan cuti dibatalkan.');
    }

    public function approve(Request $request, Leave $leave, ApproveLeave $approveLeave): RedirectResponse
    {
        $approveLeave->handle($leave, $request->user());

        return back()->with('success', 'Cuti disetujui.');
    }

    public function reject(RejectLeaveRequest $request, Leave $leave, RejectLeave $rejectLeave): RedirectResponse
    {
        $rejectLeave->handle($leave, $request->user(), $request->string('rejection_reason')->value());

        return back()->with('success', 'Cuti ditolak.');
    }
}
