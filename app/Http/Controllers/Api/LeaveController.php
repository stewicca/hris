<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    public function store(Request $request): JsonResponse
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

        $days = Carbon::parse($validated['start_date'])->diffInDays($validated['end_date']) + 1;

        if ($validated['type'] === 'annual') {
            $remaining = $employee->annualLeaveSummary(Carbon::parse($validated['start_date'])->year)['remaining'];

            if ($days > $remaining) {
                throw ValidationException::withMessages([
                    'end_date' => "Sisa cuti tahunan tidak cukup ({$remaining} hari tersisa).",
                ]);
            }
        }

        $leave = $employee->leaves()->create([
            ...$validated,
            'days' => $days,
        ]);

        return response()->json(['leave' => $leave, 'message' => 'Pengajuan cuti berhasil dikirim.'], 201);
    }
}
