<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Http\Requests\Leaves\RejectLeaveRequest;
use App\Http\Requests\Leaves\StoreLeaveRequest;
use App\Models\Employee;
use App\Models\Leave;
use App\Notifications\EmployeeNotification;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

    public function store(StoreLeaveRequest $request): RedirectResponse
    {
        $start = Carbon::parse($request->start_date);
        $end = Carbon::parse($request->end_date);
        $days = $start->diffInDays($end) + 1;

        if ($request->type === 'annual') {
            $employee = Employee::findOrFail($request->employee_id);
            $remaining = $employee->annualLeaveSummary($start->year)['remaining'];

            if ($days > $remaining) {
                throw ValidationException::withMessages([
                    'end_date' => "Sisa cuti tahunan tidak cukup ({$remaining} hari tersisa).",
                ]);
            }
        }

        Leave::create([
            ...$request->validated(),
            'days' => $days,
        ]);

        return to_route('leaves.index')->with('success', 'Pengajuan cuti berhasil disimpan.');
    }

    public function show(Leave $leave): Response
    {
        $leave->load(['employee', 'approvedBy']);

        return Inertia::render('leaves/show', [
            'leave' => $leave,
        ]);
    }

    public function destroy(Leave $leave): RedirectResponse
    {
        abort_unless($leave->isPending(), 403, 'Hanya cuti yang masih menunggu yang dapat dibatalkan.');

        $leave->delete();

        return to_route('leaves.index')->with('success', 'Pengajuan cuti dibatalkan.');
    }

    public function approve(Leave $leave): RedirectResponse
    {
        abort_unless($leave->isPending(), 403, 'Cuti ini sudah diproses.');

        $leave->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        $leave->employee->user?->notify(new EmployeeNotification(
            'Cuti Disetujui',
            "Pengajuan cuti {$leave->days} hari ({$leave->start_date->format('d M Y')} - {$leave->end_date->format('d M Y')}) telah disetujui.",
            'leave',
        ));

        return back()->with('success', 'Cuti disetujui.');
    }

    public function reject(RejectLeaveRequest $request, Leave $leave): RedirectResponse
    {
        abort_unless($leave->isPending(), 403, 'Cuti ini sudah diproses.');

        $leave->update([
            'status' => 'rejected',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'rejection_reason' => $request->rejection_reason,
        ]);

        $leave->employee->user?->notify(new EmployeeNotification(
            'Cuti Ditolak',
            "Pengajuan cuti Anda ditolak. Alasan: {$leave->rejection_reason}",
            'leave',
        ));

        return back()->with('success', 'Cuti ditolak.');
    }
}
