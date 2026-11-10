<?php

namespace App\Http\Controllers\Api;

use App\Actions\Leaves\SubmitLeave;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['message' => 'Employee profile not found.'], 404);
        }

        $leaves = $employee->leaves()->orderByDesc('created_at')->get();

        return response()->json([
            'leaves' => $leaves,
            'quota' => $employee->annualLeaveSummary(),
        ]);
    }

    public function store(Request $request, SubmitLeave $submitLeave): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return response()->json(['message' => 'Employee profile not found.'], 404);
        }

        $validated = $request->validate([
            'type' => ['required', 'in:annual,sick,permit'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $leave = $submitLeave->handle($employee, $validated);

        return response()->json(['leave' => $leave, 'message' => 'Pengajuan cuti berhasil dikirim.'], 201);
    }
}
